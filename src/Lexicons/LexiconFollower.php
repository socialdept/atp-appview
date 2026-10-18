<?php

namespace SocialDept\AtpAppview\Lexicons;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use SocialDept\AtpSchema\Authoring\BreakingChanges;
use SocialDept\AtpSchema\Authoring\Issue;
use SocialDept\AtpSchema\Authoring\LexiconDiff;
use SocialDept\AtpSchema\Authoring\LexiconFiles;
use SocialDept\AtpSchema\Parser\SchemaLoader;
use SocialDept\AtpSupport\Data\PublishedLexicon;
use SocialDept\AtpSupport\Exceptions\LexiconResolutionException;
use SocialDept\AtpSupport\Resolvers\LexiconDnsResolver;
use Throwable;

/**
 * Keeps the lexicons this AppView follows in step with what their authors
 * publish.
 *
 * Each followed NSID is resolved through its _lexicon DNS record and stored
 * in atp_lexicons, and the version in use is written to
 * atp-appview.lexicons.path, which atp-schema reads like any other source.
 * A new version replaces the one in use only when atp-schema's breaking
 * change check passes. Otherwise it is held back, with its findings, until
 * someone accepts it.
 */
class LexiconFollower
{
    public function __construct(
        protected ConnectionResolverInterface $db,
        protected LexiconDnsResolver $resolver,
        protected SchemaLoader $loader,
    ) {
    }

    /**
     * The NSIDs atp-appview.lexicons.follow lists.
     *
     * @return array<int, string>
     */
    public static function followed(): array
    {
        return array_values(array_unique(array_filter((array) config('atp-appview.lexicons.follow', []), 'is_string')));
    }

    public static function path(): string
    {
        return rtrim((string) (config('atp-appview.lexicons.path') ?? storage_path('app/atp-appview/lexicons')), '/');
    }

    /**
     * Fetch one lexicon from its author and put a new version in use, unless
     * it would break the one in use. With $force, it is put in use anyway.
     *
     * @throws RuntimeException when the author cannot be read
     */
    public function sync(string $nsid, bool $force = false): FollowResult
    {
        try {
            $published = $this->resolver->fetch($nsid);
        } catch (LexiconResolutionException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        if ($published === null) {
            return new FollowResult($nsid, FollowResult::NOT_PUBLISHED, $this->current($nsid)?->revision);
        }

        $this->track($published->did);
        $current = $this->current($nsid);
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        if ($current === null) {
            $this->put($published, null, $now, false);

            return new FollowResult($nsid, FollowResult::ADDED, 1);
        }

        $schema = json_decode($current->schema, true) ?: [];

        if (LexiconDiff::same($schema, $published->schema)) {
            $this->connection()->table('atp_lexicons')->where('nsid', $nsid)->update(['fetched_at' => $now, 'pending_cid' => null, 'pending_schema' => null, 'pending_issues' => null]);

            return new FollowResult($nsid, FollowResult::UNCHANGED, (int) $current->revision);
        }

        $breaking = array_values(array_filter(BreakingChanges::between($schema, $published->schema), fn (Issue $issue) => $issue->isError()));

        if ($breaking !== [] && ! $force) {
            $this->connection()->table('atp_lexicons')->where('nsid', $nsid)->update([
                'pending_cid' => $published->cid,
                'pending_schema' => json_encode($published->schema, JSON_UNESCAPED_SLASHES),
                'pending_issues' => json_encode(array_map(fn (Issue $issue) => $issue->toArray(), $breaking), JSON_UNESCAPED_SLASHES),
                'fetched_at' => $now,
            ]);

            Log::warning("[atp-appview] Held back a new version of {$nsid}: it would break the one in use", [
                'cid' => $published->cid,
                'issues' => array_map(fn (Issue $issue) => "[{$issue->name}] {$issue->message}", $breaking),
            ]);

            return new FollowResult($nsid, FollowResult::REFUSED, (int) $current->revision, $breaking);
        }

        $revision = $this->put($published, $current, $now, $breaking !== []);

        return new FollowResult($nsid, FollowResult::UPDATED, $revision);
    }

    /**
     * Put the held-back version of a lexicon in use.
     *
     * @throws RuntimeException when no version is held back
     */
    public function accept(string $nsid): int
    {
        $current = $this->current($nsid);

        if ($current === null || $current->pending_schema === null) {
            throw new RuntimeException("No version of {$nsid} is held back.");
        }

        $published = new PublishedLexicon($nsid, $current->did, $current->uri, $current->pending_cid, json_decode($current->pending_schema, true) ?: []);

        return $this->put($published, $current, Carbon::now('UTC')->format('Y-m-d H:i:s'), true);
    }

    /**
     * Write every followed lexicon in use to the path atp-schema reads, so a
     * fresh server or deploy has them without fetching anything.
     */
    public function writeAll(): int
    {
        $written = 0;

        foreach ($this->connection()->table('atp_lexicons')->get(['nsid', 'schema']) as $row) {
            $this->write($row->nsid, json_decode($row->schema, true) ?: []);
            $written++;
        }

        return $written;
    }

    public function current(string $nsid): ?object
    {
        return $this->connection()->table('atp_lexicons')->where('nsid', $nsid)->first();
    }

    protected function put(PublishedLexicon $published, ?object $current, string $now, bool $forced): int
    {
        $revision = $current === null ? 1 : (int) $current->revision + 1;
        $schema = json_encode($published->schema, JSON_UNESCAPED_SLASHES);

        $this->connection()->transaction(function () use ($published, $current, $now, $forced, $revision, $schema) {
            $this->connection()->table('atp_lexicons')->updateOrInsert(['nsid' => $published->nsid], [
                'did' => $published->did,
                'uri' => $published->uri,
                'cid' => $published->cid,
                'schema' => $schema,
                'revision' => $revision,
                'pending_cid' => null,
                'pending_schema' => null,
                'pending_issues' => null,
                'fetched_at' => $now,
                'updated_at' => $now,
            ]);

            $this->connection()->table('atp_lexicon_versions')->insert([
                'nsid' => $published->nsid,
                'revision' => $revision,
                'cid' => $published->cid,
                'schema' => $schema,
                'forced' => $forced,
                'created_at' => $now,
            ]);
        });

        $this->write($published->nsid, $published->schema);

        return $revision;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function write(string $nsid, array $schema): void
    {
        (new LexiconFiles(self::path()))->write($nsid, $schema);

        // This process reads the new version straight away. Others pick it
        // up from the file once their cached copy expires or they restart.
        $this->loader->clearCache($nsid);
    }

    /**
     * Have Tap follow the author's repo too, so a new version arrives as an
     * event rather than on the next scheduled sync. Tap must also have
     * com.atproto.lexicon.schema in its collection filters.
     */
    protected function track(string $did): void
    {
        if (! config('atp-appview.lexicons.track_with_tap', true) || config('atp-appview.ingest.admin_url') === null) {
            return;
        }

        try {
            app(TapAdmin::class)->addRepos([$did]);
        } catch (Throwable $e) {
            Log::info('[atp-appview] Could not ask Tap to follow a lexicon author', ['did' => $did, 'error' => $e->getMessage()]);
        }
    }

    protected function connection(): Connection
    {
        return $this->db->connection(config('atp-appview.index.connection'));
    }
}
