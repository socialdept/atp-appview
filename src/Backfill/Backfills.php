<?php

namespace SocialDept\AtpAppview\Backfill;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SocialDept\AtpAppview\Backfill\Events\BackfillFinished;
use SocialDept\AtpAppview\Backfill\Events\BackfillStarted;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Collections;
use SocialDept\AtpAppview\Ingest\IngestStats;
use SocialDept\AtpAppview\Ingest\RepoDiscovery;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use SocialDept\AtpAppview\Ingest\TapFeatureUnavailableException;
use SocialDept\AtpAppview\Models\Backfill;
use SocialDept\AtpAppview\Models\BackfillRepo;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;
use Throwable;

/**
 * Backfilling a collection the index starts ingesting, tracked so an app can
 * show progress. Tap does the backfilling. This finds the repos, hands them
 * to Tap, and watches their records arrive.
 *
 * Tap ignores repos it already follows when they are added, and its
 * collection filters only filter what it sends, so a new collection never
 * reaches those repos on its own. A Tap with resync routes backfills them
 * again while keeping what it already sent, so only the new collection's
 * records go out. On a Tap without them, repos are removed and added again,
 * which re-sends every collection and pauses each repo's live events until
 * its backfill runs.
 */
class Backfills
{
    protected const PROGRESS = 'atp-appview:backfills:progress';

    protected const RUNNING = 'atp-appview:backfills:running';

    /**
     * The source recorded for a companion collection's backfill.
     */
    public const FOLLOWED_REPOS = 'followed repos';

    protected ?bool $stillBackfilling = null;

    public function __construct(
        protected TapAdmin $tap,
        protected Blocklist $blocklist,
        protected Cache $cache,
        protected IngestStats $ingest,
    ) {
    }

    /**
     * Find every repo holding the collection and have Tap backfill it. For a
     * companion collection, the repos are the active ones the index already
     * knows, and only those Tap follows are resynced. The collection must
     * already be in Tap's filters, or Tap backfills the repos without it, and
     * the backfill fails once Tap goes quiet having delivered nothing.
     *
     * @param  (Closure(int $found): void)|null  $progress  Called every thousand repos discovered
     *
     * @throws RuntimeException when a backfill of the collection is already running
     */
    public function start(string $collection, ?string $source = null, ?Closure $progress = null): Backfill
    {
        if (Backfill::query()->where('collection', $collection)->where('status', Backfill::RUNNING)->exists()) {
            throw new RuntimeException("A backfill of {$collection} is already running.");
        }

        $companion = Collections::isCompanion($collection);
        $dids = [];

        if ($companion) {
            // Never discovered: the repos already followed, as the index knows them.
            $source = self::FOLLOWED_REPOS;
            $dids = array_fill_keys(Identity::query()->where('active', true)->pluck('did')->all(), true);
        } else {
            $source ??= (string) config('atp-appview.ingest.discovery_url');

            foreach ((new RepoDiscovery($source))->dids($collection) as $did) {
                $dids[$did] = true;

                if ($progress !== null && count($dids) % 1000 === 0) {
                    $progress(count($dids));
                }
            }
        }

        $dids = $this->blocklist->withoutBlockedRepos(array_keys($dids));
        $backfill = Backfill::query()->create(['collection' => $collection, 'source' => $source, 'started_at' => Carbon::now('UTC')]);

        try {
            $resync = $this->tap->supportsResync();
            // Repos an attempt that failed in the last hour already handed to
            // Tap, so starting again does not ask twice.
            $handed = $this->handedByFailedAttempt($collection);
            $failed = 0;

            foreach (array_chunk($dids, $this->chunk()) as $chunk) {
                BackfillRepo::query()->insert(array_map(fn (string $did) => ['backfill_id' => $backfill->id, 'did' => $did], $chunk));
                $this->alreadyIndexed($backfill, $chunk);

                foreach (array_intersect_key($handed, array_flip($chunk)) as $did => $method) {
                    $this->method($backfill, [$did], $method);
                }

                $toHand = array_values(array_diff($chunk, array_keys($handed)));

                // Every repo Tap follows, in one request, rather than one each.
                if ($companion && $resync) {
                    $this->method($backfill, $toHand, BackfillRepo::RESYNC);

                    continue;
                }

                $failed += $resync ? $this->resync($backfill, $toHand, addUntracked: ! $companion) : $this->readd($backfill, $toHand);
            }

            if ($companion && $resync && $dids !== []) {
                retry(3, fn () => $this->tap->resyncAll(), fn (int $attempt) => $attempt * 1000);
            }

            if ($dids !== [] && $failed === count($dids)) {
                throw new RuntimeException('Tap took none of the repos. The last error is kept on each repo.');
            }
        } catch (Throwable $e) {
            $backfill->update(['status' => Backfill::FAILED, 'error' => Str::limit("Handing repos to Tap failed: {$e->getMessage()}", 1000), 'finished_at' => Carbon::now('UTC')]);
            $this->forget();

            throw $e;
        }

        $this->forget();
        event(new BackfillStarted($backfill));

        return $backfill;
    }

    /**
     * Start backfills for newly ingested collections and end those Tap is
     * done with. Meant for the scheduler, every minute.
     *
     * @return array<int, string> Collections a backfill was started for
     */
    public function work(): array
    {
        $started = $this->startNew();

        foreach (Backfill::query()->where('status', Backfill::RUNNING)->get() as $backfill) {
            $this->finishIfDone($backfill);
        }

        $this->forget();

        return $started;
    }

    /**
     * Start a backfill for every ingested collection not seen before, or seen,
     * dropped from the config and added back.
     *
     * The first time this runs it has no history, so collections already
     * holding records count as backfilled (appview:discover did it) and only
     * empty ones are backfilled, which is what a fresh app wants. After that,
     * every new collection is backfilled, even if a few live records reached
     * it first.
     *
     * Except while Tap is still backfilling on that first run, as on a fresh
     * install, where Tap starts on its signal collection's repos before the
     * scheduler first runs. Those records are not a finished backfill, so a
     * Tap with resync routes is asked to backfill again, which tracks the
     * rest as they arrive and sends nothing for repos it already finished.
     *
     * @return array<int, string> Collections a backfill was started for
     */
    public function startNew(): array
    {
        $connection = $this->connection();
        $this->stillBackfilling = null;
        $configured = Collections::all();
        $known = $connection->table('atp_ingest_collections')->get()->keyBy('collection');
        $now = Carbon::now('UTC');

        $connection->table('atp_ingest_collections')
            ->whereNotIn('collection', $configured)
            ->whereNull('removed_at')
            ->update(['removed_at' => $now]);

        $firstRun = $known->isEmpty();
        $started = [];

        foreach ($configured as $collection) {
            $row = $known[$collection] ?? null;

            if ($row !== null && $row->removed_at === null) {
                continue;
            }

            $baseline = $firstRun
                && Record::withTrashed()->withHidden()->where('collection', $collection)->exists()
                && ! $this->stillBackfilling();
            $backfillId = null;

            if (! $baseline) {
                try {
                    $backfillId = $this->start($collection)->id;
                    $started[] = $collection;
                } catch (Throwable $e) {
                    // Left unrecorded, so the next run tries again.
                    report($e);

                    continue;
                }
            }

            $connection->table('atp_ingest_collections')->updateOrInsert(
                ['collection' => $collection],
                ['first_seen_at' => $row?->first_seen_at ?? $now, 'removed_at' => null, 'backfill_id' => $backfillId, 'baseline' => $baseline],
            );
        }

        return $started;
    }

    /**
     * Records Tap backfilled for running backfills, from BackfillTracker. A
     * repo counts as delivered once its first record arrives.
     *
     * @param  array<int, string>  $backfillIds  Every running backfill of the records' collection
     * @param  array<string, int>  $counts  Backfilled records by repo
     */
    public function arrived(array $backfillIds, array $counts): void
    {
        if ($backfillIds === [] || $counts === []) {
            return;
        }

        $cases = implode(' ', array_fill(0, count($counts), 'when ? then ?'));
        $repos = implode(', ', array_fill(0, count($counts), '?'));
        $ids = implode(', ', array_fill(0, count($backfillIds), '?'));

        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $this->connection()->table('atp_backfills')->whereIn('id', $backfillIds)->update(['last_delivery_at' => $now]);

        // One statement for the batch: each repo's count added, and its first
        // arrival kept.
        $this->connection()->update(
            "update atp_backfill_repos set records = records + case did {$cases} else 0 end, delivered_at = coalesce(delivered_at, ?) where backfill_id in ({$ids}) and did in ({$repos})",
            [
                ...collect($counts)->flatMap(fn (int $n, string $did) => [$did, $n])->all(),
                $now,
                ...array_values($backfillIds),
                ...array_keys($counts),
            ],
        );
    }

    /**
     * Running backfills' ids by collection, for BackfillTracker. Cached
     * briefly, since it is asked on every batch.
     *
     * @return array<string, array<int, int>>
     */
    public function running(): array
    {
        return $this->cache->remember(self::RUNNING, 10, fn () => Backfill::query()
            ->where('status', Backfill::RUNNING)
            ->get(['id', 'collection'])
            ->groupBy('collection')
            ->map(fn ($backfills) => $backfills->pluck('id')->all())
            ->all());
    }

    /**
     * Progress of running backfills, and of those that ended in the last
     * hour, running first and least far along first. Cached for a few
     * seconds, since a page might ask on every request.
     *
     * @return list<array{id: int, collection: string, status: string, error: string|null, startedAt: string, finishedAt: string|null, repos: int, delivered: int, percent: int, records: int, unreachable: int, perMinute: float, etaMinutes: int|null}>
     */
    public function progress(): array
    {
        return $this->cache->remember(self::PROGRESS, 5, function () {
            $backfills = Backfill::query()
                ->where(fn ($query) => $query->where('status', Backfill::RUNNING)->orWhere('finished_at', '>=', Carbon::now('UTC')->subHour()))
                ->get();

            if ($backfills->isEmpty()) {
                return [];
            }

            $counts = $this->connection()->table('atp_backfill_repos')
                ->whereIn('backfill_id', $backfills->pluck('id'))
                ->groupBy('backfill_id')
                ->select('backfill_id')
                ->selectRaw('count(*) as total, count(delivered_at) as delivered, sum(records) as records')
                ->selectRaw('sum(case when delivered_at is null and error is not null then 1 else 0 end) as unreachable')
                ->selectRaw('sum(case when delivered_at >= ? then 1 else 0 end) as recent', [Carbon::now('UTC')->subMinutes(5)->format('Y-m-d H:i:s')])
                ->get()
                ->keyBy('backfill_id');

            // An ended backfill another of the same collection started after
            // is superseded, such as a failed attempt the worker retried.
            $backfills = $backfills->reject(fn (Backfill $backfill) => $backfill->status !== Backfill::RUNNING
                && $backfills->contains(fn (Backfill $other) => $other->collection === $backfill->collection && $other->id > $backfill->id));

            return $backfills->map(fn (Backfill $backfill) => $this->describe($backfill, $counts[$backfill->id] ?? null))
                ->sortBy(fn (array $b) => [$b['status'] === Backfill::RUNNING ? 0 : 1, $b['percent']])
                ->values()
                ->all();
        });
    }

    /**
     * The running backfills only, for "is a backfill happening".
     *
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        return array_values(array_filter($this->progress(), fn (array $b) => $b['status'] === Backfill::RUNNING));
    }

    public function forget(): void
    {
        $this->cache->forget(self::PROGRESS);
        $this->cache->forget(self::RUNNING);
    }

    /**
     * Count repos already holding records of the collection as delivered. A
     * resync only sends what Tap never delivered, so for a collection run
     * again those repos send nothing, and without this the backfill would
     * fail for want of deliveries. On a first backfill it only covers repos a
     * few live records reached, whose older records still arrive.
     *
     * @param  array<int, string>  $dids
     */
    protected function alreadyIndexed(Backfill $backfill, array $dids): void
    {
        $indexed = Record::withTrashed()->withHidden()
            ->where('collection', $backfill->collection)
            ->whereIn('did', $dids)
            ->distinct()
            ->pluck('did')
            ->all();

        if ($indexed !== []) {
            BackfillRepo::query()->where('backfill_id', $backfill->id)->whereIn('did', $indexed)->update(['delivered_at' => Carbon::now('UTC')]);
        }
    }

    /**
     * Resync repos Tap tracks, and add those it does not. A companion
     * collection's backfill never adds a repo: one Tap does not follow is
     * left out of the backfill instead.
     *
     * Each request is retried, and a repo Tap still does not take keeps the
     * error and stays in the backfill, so one slow answer from a busy Tap
     * does not throw away every repo handed over before it.
     *
     * @param  array<int, string>  $dids
     * @return int Repos Tap did not take
     */
    protected function resync(Backfill $backfill, array $dids, bool $addUntracked = true): int
    {
        $resynced = [];
        $untracked = [];
        $failed = [];

        foreach ($dids as $did) {
            try {
                $answer = $this->retrying(fn () => $this->tap->resyncRepo($did));
            } catch (Throwable $e) {
                $failed[$did] = $e->getMessage();

                continue;
            }

            $answer === null ? $untracked[] = $did : $resynced[] = $did;
        }

        if ($untracked !== [] && ! $addUntracked) {
            BackfillRepo::query()->where('backfill_id', $backfill->id)->whereIn('did', $untracked)->delete();
            $untracked = [];
        }

        if ($untracked !== []) {
            try {
                $this->retrying(fn () => $this->tap->addRepos($untracked));
                $this->method($backfill, $untracked, BackfillRepo::ADD);
            } catch (Throwable $e) {
                $failed += array_fill_keys($untracked, $e->getMessage());
            }
        }

        $this->method($backfill, $resynced, BackfillRepo::RESYNC);
        $this->failed($backfill, $failed);

        return count($failed);
    }

    /**
     * Remove and add repos, on a Tap without resync. The add is retried, since
     * a repo removed but not added again is no longer followed at all.
     *
     * @param  array<int, string>  $dids
     * @return int Repos Tap did not take
     */
    protected function readd(Backfill $backfill, array $dids): int
    {
        if ($dids === []) {
            return 0;
        }

        try {
            $this->retrying(fn () => $this->tap->removeRepos($dids));
            $this->retrying(fn () => $this->tap->addRepos($dids));
        } catch (Throwable $e) {
            $this->failed($backfill, array_fill_keys($dids, $e->getMessage()));

            return count($dids);
        }

        $this->method($backfill, $dids, BackfillRepo::READD);

        return 0;
    }

    /**
     * A Tap request, tried three times with a growing pause. A Tap without
     * the feature is not retried.
     */
    protected function retrying(Closure $request): mixed
    {
        return retry(3, $request, fn (int $attempt) => $attempt * 500, fn (Throwable $e) => ! $e instanceof TapFeatureUnavailableException);
    }

    /**
     * Keep why Tap did not take each repo. They stay in the backfill and count
     * as unreachable unless they deliver anyway.
     *
     * @param  array<string, string>  $errors  Error by DID
     */
    protected function failed(Backfill $backfill, array $errors): void
    {
        foreach ($errors as $did => $error) {
            BackfillRepo::query()->where('backfill_id', $backfill->id)->where('did', $did)
                ->update(['error' => Str::limit("Tap did not take it: {$error}", 1000)]);
        }
    }

    /**
     * Repos the last attempt at this collection handed to Tap, when it failed
     * within the hour, with how each was handed.
     *
     * @return array<string, string> Method by DID
     */
    protected function handedByFailedAttempt(string $collection): array
    {
        $previous = Backfill::query()->where('collection', $collection)->latest('id')->skip(1)->first();

        if ($previous === null || $previous->status !== Backfill::FAILED || $previous->finished_at?->lt(Carbon::now('UTC')->subHour())) {
            return [];
        }

        return BackfillRepo::query()->where('backfill_id', $previous->id)
            ->whereNotNull('method')->whereNull('error')
            ->pluck('method', 'did')
            ->all();
    }

    /**
     * @param  array<int, string>  $dids
     */
    protected function method(Backfill $backfill, array $dids, string $method): void
    {
        if ($dids !== []) {
            BackfillRepo::query()->where('backfill_id', $backfill->id)->whereIn('did', $dids)->update(['method' => $method]);
        }
    }

    /**
     * A backfill is done once Tap has stopped sending its records: no delivery
     * for a short while and an empty outbox. A repo counts as delivered at its
     * first record, so every repo having delivered is not enough on its own,
     * since the rest of their records may still be on the way.
     *
     * Repos that never delivered get the longer quiet window first. If
     * nothing at all arrived by then, Tap most likely backfilled the repos
     * without the collection in its filters, so it fails instead of
     * finishing.
     */
    protected function finishIfDone(Backfill $backfill): void
    {
        $repos = BackfillRepo::query()->where('backfill_id', $backfill->id);
        $total = (clone $repos)->count();
        $delivered = (clone $repos)->whereNotNull('delivered_at')->count();
        $last = Carbon::parse($backfill->last_delivery_at ?? $backfill->started_at, 'UTC');

        $wait = $delivered < $total
            ? Carbon::now('UTC')->subMinutes($this->quietMinutes())
            : Carbon::now('UTC')->subSeconds($this->settleSeconds());

        if (! $last->lt($wait) || ! $this->tapDrained()) {
            return;
        }

        if ($delivered < $total) {
            $this->explainStragglers($backfill);
        }

        $failed = $total > 0 && $delivered === 0;

        $backfill->update([
            'status' => $failed ? Backfill::FAILED : Backfill::FINISHED,
            'error' => $failed ? "Tap delivered nothing for {$backfill->collection}. Check that it is in Tap's collection filters, then run appview:backfill again." : null,
            'finished_at' => Carbon::now('UTC'),
        ]);

        event(new BackfillFinished($backfill));
    }

    /**
     * Note why repos that never delivered did not: Tap could not fetch them
     * (its error), or they had nothing in the collection.
     */
    protected function explainStragglers(Backfill $backfill): void
    {
        $stragglers = BackfillRepo::query()->where('backfill_id', $backfill->id)->whereNull('delivered_at')->limit(500)->pluck('did');

        foreach ($stragglers as $did) {
            try {
                $info = $this->tap->info($did);
                $error = ($info['state'] ?? null) === 'error' ? Str::limit((string) ($info['error'] ?? 'unreachable'), 500) : null;
            } catch (Throwable) {
                continue;
            }

            BackfillRepo::query()->where('backfill_id', $backfill->id)->where('did', $did)->update(['error' => $error]);
        }
    }

    /**
     * Whether Tap is mid-backfill and can backfill again without re-sending
     * what it already sent. Asked once per run.
     */
    protected function stillBackfilling(): bool
    {
        try {
            return $this->stillBackfilling ??= $this->ingest->backfilling() && $this->tap->supportsResync();
        } catch (Throwable) {
            return $this->stillBackfilling = false;
        }
    }

    protected function tapDrained(): bool
    {
        try {
            // Only the outbox: the resync buffer holds live events for repos
            // stuck mid-backfill, which may never empty.
            return (int) (array_values($this->tap->stats('outbox-buffer'))[0] ?? 0) === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function describe(Backfill $backfill, ?object $row): array
    {
        $total = (int) ($row->total ?? 0);
        $delivered = (int) ($row->delivered ?? 0);
        $perMinute = (int) ($row->recent ?? 0) / 5;

        return [
            'id' => $backfill->id,
            'collection' => $backfill->collection,
            'status' => $backfill->status,
            'error' => $backfill->error,
            'startedAt' => $backfill->started_at->toIso8601ZuluString(),
            'finishedAt' => $backfill->finished_at?->toIso8601ZuluString(),
            'repos' => $total,
            'delivered' => $delivered,
            'percent' => $total === 0 ? 100 : (int) floor($delivered / $total * 100),
            'records' => (int) ($row->records ?? 0),
            // Of the repos that never delivered, those Tap could not fetch.
            // The rest had nothing in the collection.
            'unreachable' => (int) ($row->unreachable ?? 0),
            'perMinute' => $perMinute,
            'etaMinutes' => ! $backfill->isRunning() || $perMinute <= 0 ? null : (int) ceil(($total - $delivered) / $perMinute),
        ];
    }

    protected function chunk(): int
    {
        return max(1, (int) config('atp-appview.backfill.chunk', 500));
    }

    protected function settleSeconds(): int
    {
        return max(0, (int) config('atp-appview.backfill.settle_seconds', 60));
    }

    protected function quietMinutes(): int
    {
        return max(1, (int) config('atp-appview.backfill.quiet_minutes', 10));
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
