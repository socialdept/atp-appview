<?php

namespace SocialDept\AtpAppview\Blocklist;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use SocialDept\AtpAppview\Models\Block;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;
use Throwable;

/**
 * Rules that keep records out of the index, by repo or by PDS host, for
 * every collection or only some.
 *
 * Rules apply in three places:
 *
 * - At ingest, matching record events are dropped before any handler sees
 *   them, and acknowledged so Tap does not redeliver them.
 * - In Tap, a blanket rule stops Tap following the repos it covers, so Tap
 *   does not spend a backfill on them.
 * - In appview:discover, repos under a blanket rule are never added to Tap.
 *
 * Rules live in atp_blocks. Changing them bumps a version in the cache, and a
 * running consumer reloads them before its next batch.
 */
class Blocklist
{
    public const VERSION_KEY = 'atp-appview:blocklist:version';

    protected mixed $loadedVersion = null;

    protected bool $loaded = false;

    /** @var array<string, array<int, Block>> */
    protected array $didRules = [];

    /** @var array<int, Block> */
    protected array $hostRules = [];

    public function __construct(
        protected Cache $cache,
        protected PdsLookup $pds,
        protected TapAdmin $tap,
    ) {
    }

    /**
     * Add a rule, or update the note of an existing one.
     *
     * @throws InvalidArgumentException For a subject or collection that does not parse
     */
    public function block(string $subject, ?string $collection = null, ?string $note = null, bool $purge = false, ?Closure $progress = null): BlockOutcome
    {
        [$kind, $subject] = Patterns::subject($subject);
        $collection = Patterns::collectionPattern($collection);

        $block = Block::query()->updateOrCreate(
            ['subject' => $subject, 'collection' => $collection],
            ['kind' => $kind, 'note' => $note],
        );

        $this->changed();

        $purged = $purge ? $this->purge($block, $progress) : 0;

        if (! $block->isBlanket()) {
            return new BlockOutcome($block, $purged);
        }

        $dids = array_keys($this->reposUnder($block));

        try {
            $this->chunked($dids, fn (array $chunk) => $this->tap->removeRepos($chunk));
        } catch (Throwable $e) {
            return new BlockOutcome($block, $purged, tapError: $e->getMessage());
        }

        return new BlockOutcome($block, $purged, removedFromTap: $dids);
    }

    /**
     * Remove a rule. The repos it covered are backfilled again, since the
     * records it dropped were never indexed.
     *
     * @return BlockOutcome|null Null when there was no such rule
     */
    public function unblock(string $subject, ?string $collection = null): ?BlockOutcome
    {
        [, $subject] = Patterns::subject($subject);
        $collection = Patterns::collectionPattern($collection);

        $block = Block::query()->where('subject', $subject)->where('collection', $collection)->first();

        if ($block === null) {
            return null;
        }

        $block->delete();
        $this->changed();

        // Repos still under another blanket rule stay out of Tap.
        $repos = $this->reposUnder($block);
        $dids = array_values(array_filter(array_keys($repos), fn (string $did) => ! $this->blocksRepo($did, $repos[$did])));

        try {
            if ($block->isBlanket()) {
                // Tap stopped following these, so adding them back backfills them.
                $this->chunked($dids, fn (array $chunk) => $this->tap->addRepos($chunk));
            } else {
                // Tap still follows these. Removing and adding them again is
                // what makes it backfill the records the rule dropped.
                $this->chunked($dids, function (array $chunk) {
                    $this->tap->removeRepos($chunk);
                    $this->tap->addRepos($chunk);
                });
            }
        } catch (Throwable $e) {
            return new BlockOutcome($block, tapError: $e->getMessage());
        }

        return new BlockOutcome($block, resyncedInTap: $dids);
    }

    /**
     * Delete indexed records a rule covers. For a host rule, every known
     * repo's PDS is resolved first, so this can take a while.
     *
     * @param  (Closure(string, int, int): void)|null  $progress  Called with a stage, a count done and a total
     */
    public function purge(Block $block, ?Closure $progress = null): int
    {
        $dids = $block->kind === BlockKind::Did ? [$block->subject] : $this->hostedRepos($block, $progress);
        $purged = 0;

        foreach (array_chunk($dids, 500) as $chunk) {
            $purged += $this->recordsCovered($block, $chunk)->forceDelete();
        }

        return $purged;
    }

    /**
     * The batch without the record events any rule covers. Identity events
     * always pass, so handles stay current for blocked repos.
     */
    public function filter(Batch $batch): Batch
    {
        $this->refresh();

        if ($this->didRules === [] && $this->hostRules === []) {
            return $batch;
        }

        $records = $batch->records();
        $pds = $this->hostRules === [] ? [] : $this->pds->forDids(array_map(fn (RecordEvent $event) => $event->did, $records));

        $hits = [];
        $kept = [];

        foreach ($batch->events as $event) {
            $rule = $event instanceof RecordEvent ? $this->ruleFor($event->did, $event->collection, $pds[$event->did] ?? null) : null;

            if ($rule === null) {
                $kept[] = $event;
            } else {
                $hits[$rule->id] = ($hits[$rule->id] ?? 0) + 1;
            }
        }

        if ($hits === []) {
            return $batch;
        }

        $this->recordHits($hits);

        return new Batch($kept);
    }

    /**
     * The rule that keeps a record out, if any.
     */
    public function ruleFor(string $did, string $collection, ?string $pds = null): ?Block
    {
        $this->refresh();

        foreach ($this->didRules[$did] ?? [] as $rule) {
            if ($rule->coversCollection($collection)) {
                return $rule;
            }
        }

        foreach ($this->hostRules as $rule) {
            if ($rule->coversCollection($collection) && $rule->coversPds($pds)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Whether a blanket rule keeps this repo out entirely, judged by its DID
     * and its PDS as last resolved.
     */
    public function blocksRepo(string $did, ?string $pds = null): bool
    {
        $this->refresh();

        foreach ($this->didRules[$did] ?? [] as $rule) {
            if ($rule->isBlanket()) {
                return true;
            }
        }

        foreach ($this->hostRules as $rule) {
            if ($rule->isBlanket() && $rule->coversPds($pds)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The DIDs a blanket rule does not keep out. PDS hosts are judged as last
     * resolved, without looking any up.
     *
     * @param  array<int, string>  $dids
     * @return array<int, string>
     */
    public function withoutBlockedRepos(array $dids): array
    {
        $this->refresh();

        if ($this->didRules === [] && $this->hostRules === []) {
            return $dids;
        }

        $pds = [];

        if ($this->hostRules !== []) {
            foreach (array_chunk($dids, 1000) as $chunk) {
                $pds += Identity::query()->whereIn('did', $chunk)->whereNotNull('pds')->pluck('pds', 'did')->all();
            }
        }

        return array_values(array_filter($dids, fn (string $did) => ! $this->blocksRepo($did, $pds[$did] ?? null)));
    }

    /**
     * Reload the rules on the next check, whatever the cached version says.
     */
    public function forget(): void
    {
        $this->loaded = false;
    }

    protected function refresh(): void
    {
        $version = $this->cache->get(self::VERSION_KEY);

        if ($this->loaded && $version == $this->loadedVersion) {
            return;
        }

        $this->didRules = [];
        $this->hostRules = [];

        foreach (Block::query()->orderBy('id')->get() as $rule) {
            $rule->kind === BlockKind::Did
                ? $this->didRules[$rule->subject][] = $rule
                : $this->hostRules[] = $rule;
        }

        $this->loaded = true;
        $this->loadedVersion = $version;
    }

    protected function changed(): void
    {
        $this->cache->forever(self::VERSION_KEY, Carbon::now()->getTimestampMs().'.'.random_int(0, PHP_INT_MAX));
        $this->forget();
    }

    /**
     * Repos a rule covers that Tap should stop or start following: the DID
     * itself, or every known repo whose PDS is under the host.
     *
     * @return array<string, string|null> PDS by DID
     */
    protected function reposUnder(Block $block): array
    {
        if ($block->kind === BlockKind::Did) {
            return [$block->subject => Identity::query()->whereKey($block->subject)->value('pds')];
        }

        return Identity::query()
            ->whereNotNull('pds')
            ->pluck('pds', 'did')
            ->filter(fn (string $pds) => $block->coversPds($pds))
            ->all();
    }

    /**
     * Every indexed repo hosted under a host rule, resolving any PDS not
     * already known.
     *
     * @param  (Closure(string, int, int): void)|null  $progress
     * @return array<int, string>
     */
    protected function hostedRepos(Block $block, ?Closure $progress): array
    {
        $dids = Record::withTrashed()->distinct()->pluck('did')->all();
        $pds = [];

        foreach (array_chunk($dids, 1000) as $chunk) {
            $pds += Identity::query()->whereIn('did', $chunk)->whereNotNull('pds_resolved_at')->pluck('pds', 'did')->all();
        }

        $unknown = array_values(array_diff($dids, array_keys($pds)));

        if ($unknown !== []) {
            $pds += $this->pds->resolve($unknown, $progress === null ? null : fn (int $done) => $progress('resolving', $done, count($unknown)));
        }

        return array_keys(array_filter($pds, fn (?string $url) => $block->coversPds($url)));
    }

    /**
     * @param  array<int, string>  $dids
     */
    protected function recordsCovered(Block $block, array $dids): Builder
    {
        $query = Record::withTrashed()->whereIn('did', $dids);

        if ($block->collection === Block::EVERY_COLLECTION) {
            return $query;
        }

        return str_ends_with($block->collection, '.*')
            // NSIDs never contain % or _, so the prefix needs no escaping.
            ? $query->where('collection', 'like', substr($block->collection, 0, -1).'%')
            : $query->where('collection', $block->collection);
    }

    /**
     * @param  array<int, int>  $hits  Records dropped, by rule id
     */
    protected function recordHits(array $hits): void
    {
        $now = Carbon::now();

        foreach ($hits as $id => $count) {
            Block::query()->whereKey($id)->incrementEach(['blocked_count' => $count], ['last_blocked_at' => $now]);
        }
    }

    /**
     * @param  array<int, string>  $dids
     * @param  Closure(array<int, string>): void  $send
     */
    protected function chunked(array $dids, Closure $send): void
    {
        foreach (array_chunk($dids, 1000) as $chunk) {
            $send($chunk);
        }
    }
}
