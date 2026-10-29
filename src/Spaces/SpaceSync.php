<?php

namespace SocialDept\AtpAppview\Spaces;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use SocialDept\AtpAppview\Features\Spaces as SpacesFeature;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Client\SpaceReader;
use SocialDept\AtpSpaces\Contracts\CredentialStore;
use SocialDept\AtpSpaces\Exceptions\CredentialException;
use SocialDept\AtpSpaces\Exceptions\SpacesException;
use SocialDept\AtpSpaces\Repo\Commit;
use SocialDept\AtpSpaces\Repo\RepoCommit;
use SocialDept\AtpSpaces\Repo\RepoOp;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Spaces;
use Throwable;

/**
 * Keeps the AppView's copy of the spaces the app follows.
 *
 * A space is synced repo by repo from each member's own PDS, with one
 * credential for the whole space: the one stored for the account that
 * followed it. A repo is read from its operation log since the revision
 * last synced, and the ops are checked against the repo's commit by
 * folding them into the set hash kept for it. When they do not match, or
 * the log no longer reaches back that far, the whole repo is read again
 * from its archive and replaces the copy.
 *
 * Records go through the configured ingest pipes (the blocklist, repairs
 * and hooks) and then only SpaceRecordIndexer, so they land in
 * atp_space_records and never in atp_records.
 *
 * Without a usable stored credential a space is marked stale and waits,
 * and the next sync after one is stored catches it up from its cursor.
 */
class SpaceSync
{
    public const SPACES = 'atp_spaces';

    public const REPOS = 'atp_space_repos';

    public function __construct(
        protected Container $container,
        protected Spaces $spaces,
        protected CredentialStore $credentials,
    ) {
    }

    /**
     * Follow a space, syncing it with this credential's account from now
     * on, grant that account the space, and queue its first sync.
     * Following again changes the account.
     *
     * @throws LogicException when the spaces feature is off
     * @throws InvalidArgumentException when atp-spaces lists types and this space's is not one
     */
    public function follow(SpaceCredential $credential): SpaceRef
    {
        SpacesFeature::ensureOn();

        $space = $credential->space;
        $types = $this->spaces->types();

        if ($types->all() !== [] && ! $types->has($space->type)) {
            throw new InvalidArgumentException("{$space->type} is not a space type in atp-spaces.types, so {$space} cannot be followed.");
        }

        $this->credentials->put($credential);

        $now = $this->now();
        $row = $this->state($space);
        $values = [
            'credential_did' => $credential->userDid,
            'stale_at' => null,
            'last_error' => null,
            'updated_at' => $now,
        ];

        if ($row === null) {
            $this->connection()->table(self::SPACES)->insert([
                'space' => (string) $space,
                'authority' => $space->authority,
                'type' => $space->type,
                'skey' => $space->skey,
                'created_at' => $now,
                ...$values,
            ]);
        } else {
            // A space deleted and made again starts over.
            $this->connection()->table(self::SPACES)->where('id', $row->id)->update($row->deleted_at === null ? $values : [
                ...$values,
                'space_rev' => null,
                'notify_expires_at' => null,
                'deleted_at' => null,
            ]);
        }

        // The authority issued this account a credential, so it may read.
        $this->container->make(SpaceGrants::class)->grant($credential);

        SyncSpace::dispatch((string) $space);

        return $space;
    }

    /**
     * Stop following a space: withdraw the notification registration and
     * drop the copy, its grants and its cached blobs.
     */
    public function unfollow(SpaceRef $space): void
    {
        $row = $this->state($space);

        if ($row === null) {
            return;
        }

        if ($row->deleted_at === null && $row->notify_expires_at !== null && ($reader = $this->reader($space, $row, markStale: false)) !== null) {
            try {
                $reader->unregisterNotify($this->service());
            } catch (SpacesException $e) {
                // The registration lapses on its own.
                report($e);
            }
        }

        $this->connection()->transaction(function (Connection $connection) use ($space) {
            $this->container->make(SpaceRecordValues::class)->forget($connection, (string) $space);
            $connection->table(SpaceRecordIndexer::TABLE)->where('space', (string) $space)->delete();
            $connection->table(self::REPOS)->where('space', (string) $space)->delete();
            $connection->table(SpaceGrants::TABLE)->where('space', (string) $space)->delete();
            $connection->table(self::SPACES)->where('space', (string) $space)->delete();
        });

        $this->container->make(SpaceBlobs::class)->forget($space);
    }

    /**
     * Whether the app follows this space and it has not been deleted.
     */
    public function follows(SpaceRef $space): bool
    {
        $row = $this->state($space);

        return $row !== null && $row->deleted_at === null;
    }

    /**
     * A followed space's row in atp_spaces, deleted or not, or null.
     */
    public function state(SpaceRef $space): ?object
    {
        return $this->connection()->table(self::SPACES)->where('space', (string) $space)->first();
    }

    /**
     * Catch a space up: renew its notification registration when it is
     * close to lapsing, then page through its repos from the stored
     * spaceRev and sync each one whose revision moved.
     *
     * @return bool Whether it synced, false when it is not followed, was deleted or is stale
     *
     * @throws Throwable when a read fails, after keeping it as the last error
     */
    public function sync(SpaceRef $space): bool
    {
        $row = $this->state($space);

        if ($row === null || $row->deleted_at !== null || ($reader = $this->reader($space, $row)) === null) {
            return false;
        }

        try {
            $this->register($space, $reader, $row);

            $known = $this->connection()->table(self::REPOS)->where('space', (string) $space)->pluck('repo_rev', 'did')->all();
            $cursor = $row->space_rev;

            do {
                $page = $reader->listRepos($this->pageSize(), $cursor);

                foreach ($page['repos'] as $entry) {
                    $did = $entry['did'] ?? null;

                    if (! is_string($did) || (isset($entry['repoRev']) && ($known[$did] ?? null) === $entry['repoRev'])) {
                        continue;
                    }

                    $known[$did] = $this->pull($space, $did, $reader);
                }

                $previous = $cursor;
                $cursor = is_string($page['cursor']) && $page['cursor'] !== '' ? $page['cursor'] : null;

                // Only once every repo on the page is synced, so a failure
                // reads the page again next time.
                if ($cursor !== null) {
                    $this->connection()->table(self::SPACES)->where('id', $row->id)->update(['space_rev' => $cursor]);
                }
            } while ($cursor !== null && $cursor !== $previous && $page['repos'] !== []);

            $this->connection()->table(self::SPACES)->where('id', $row->id)->update([
                'synced_at' => $this->now(),
                'stale_at' => null,
                'last_error' => null,
            ]);

            return true;
        } catch (CredentialException $e) {
            return $this->refused($space, $e);
        } catch (Throwable $e) {
            $this->failed($space, $e);

            throw $e;
        }
    }

    /**
     * Sync one member's repo in a space, as a notification of a write asks.
     *
     * @return bool Whether it synced, false when the space is not followed, was deleted or is stale
     *
     * @throws Throwable when a read fails, after keeping it as the last error
     */
    public function syncRepo(SpaceRef $space, string $did): bool
    {
        $row = $this->state($space);

        if ($row === null || $row->deleted_at !== null || ($reader = $this->reader($space, $row)) === null) {
            return false;
        }

        try {
            $this->pull($space, $did, $reader);

            return true;
        } catch (CredentialException $e) {
            return $this->refused($space, $e);
        } catch (Throwable $e) {
            $this->failed($space, $e);

            throw $e;
        }
    }

    /**
     * Advance the stored spaceRev to a notification's, when it still holds
     * the one the notification follows. A notification handled out of
     * order leaves it alone, so the next one starts a full sync.
     */
    public function advance(SpaceRef $space, ?string $from, string $to): void
    {
        $this->connection()->table(self::SPACES)
            ->where('space', (string) $space)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $from === null ? $query->whereNull('space_rev') : $query->where('space_rev', $from))
            ->update(['space_rev' => $to]);
    }

    /**
     * Drop the copy of a deleted space: tombstone its records, forget its
     * repos, drop its grants and cached blobs, and mark it deleted so
     * nothing syncs it again. Following it again starts over.
     */
    public function deleted(SpaceRef $space): void
    {
        if ($this->state($space) === null) {
            return;
        }

        $now = $this->now();

        $this->connection()->transaction(function (Connection $connection) use ($space, $now) {
            $this->container->make(SpaceRecordValues::class)->forget($connection, (string) $space);
            $connection->table(SpaceRecordIndexer::TABLE)
                ->where('space', (string) $space)
                ->whereNull('deleted_at')
                ->update(['record' => null, 'repair' => null, 'cid' => null, 'deleted_at' => $now]);

            $connection->table(self::REPOS)->where('space', (string) $space)->delete();
            $connection->table(SpaceGrants::TABLE)->where('space', (string) $space)->delete();

            $connection->table(self::SPACES)->where('space', (string) $space)->update([
                'notify_expires_at' => null,
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $this->container->make(SpaceBlobs::class)->forget($space);
    }

    /**
     * A blob from a member's repo in the space, read with the space's sync
     * credential. The bytes are as the host sent them, not yet checked
     * against the CID.
     *
     * @return string|null The bytes, or null when the space is not followed, was deleted or is stale
     *
     * @throws SpacesException when the read fails
     */
    public function blob(SpaceRef $space, string $did, string $cid): ?string
    {
        $row = $this->state($space);

        if ($row === null || $row->deleted_at !== null || ($reader = $this->reader($space, $row)) === null) {
            return null;
        }

        try {
            return $reader->getBlob($did, $cid);
        } catch (CredentialException $e) {
            $this->refused($space, $e);

            return null;
        }
    }

    /**
     * The spaces followed and not deleted.
     *
     * @return array<int, SpaceRef>
     */
    public function followed(): array
    {
        return $this->connection()->table(self::SPACES)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('space')
            ->map(fn (string $space) => SpaceRef::parse($space))
            ->all();
    }

    /**
     * Bring one repo up to date, from its operation log when the ops add up
     * to its commit, else from its archive.
     *
     * @return string The revision synced to
     */
    protected function pull(SpaceRef $space, string $did, SpaceReader $reader): string
    {
        $repo = $this->connection()->table(self::REPOS)->where('space', (string) $space)->where('did', $did)->first();
        $pulled = $this->ops($reader, $did, $repo?->repo_rev);

        if ($pulled !== null) {
            ['ops' => $ops, 'commit' => $commit] = $pulled;

            // Every op counts toward the hash, whatever a hook later does
            // with its record, since the hash describes the repo.
            $hash = $this->hash($repo)->applyOps($ops);

            // An op the host sent without its record cannot be written.
            $complete = array_filter($ops, fn (RepoOp $op) => ! $op->isDelete() && $op->value === null) === [];

            if ($complete && $hash->matches($commit)) {
                $this->write($space, $did, $commit->rev, $hash, array_map(fn (RepoOp $op) => $this->event($space, $did, $op, $commit), $ops));

                return $commit->rev;
            }
        }

        return $this->replace($space, $did, $reader);
    }

    /**
     * Every op in a repo's log since a revision, with the commit at its
     * head, or null when the log cannot give them.
     *
     * @return array{ops: array<int, RepoOp>, commit: Commit}|null
     *
     * @throws CredentialException
     */
    protected function ops(SpaceReader $reader, string $did, ?string $since): ?array
    {
        $ops = [];
        $cursor = null;

        do {
            try {
                $page = $reader->listRepoOps($did, $since, $this->pageSize(), $cursor);
            } catch (CredentialException $e) {
                throw $e;
            } catch (SpacesException) {
                // Such as a log compacted past the revision asked for.
                return null;
            }

            array_push($ops, ...$page['ops']);

            // The commit comes with the page that reaches the head.
            if ($page['commit'] !== null) {
                return ['ops' => $ops, 'commit' => $page['commit']];
            }

            $previous = $cursor;
            $cursor = $page['cursor'];
        } while ($cursor !== null && $cursor !== $previous);

        return null;
    }

    /**
     * Replace the copy of a repo with its archive: every record in it, and
     * a tombstone for each one the copy holds that it no longer has.
     *
     * @return string The revision synced to
     */
    protected function replace(SpaceRef $space, string $did, SpaceReader $reader): string
    {
        ['commit' => $commit, 'records' => $records] = $reader->getRepo($did);

        $events = [];
        $present = [];

        foreach ($records as $record) {
            $event = new RecordEvent(0, $did, $record['collection'], $record['rkey'], RecordAction::Create, $record['cid'], $commit->rev, $record['value'], false, space: (string) $space);
            $events[] = $event;
            $present[$event->uri()] = true;
        }

        $held = $this->connection()->table(SpaceRecordIndexer::TABLE)
            ->where('space', (string) $space)
            ->where('did', $did)
            ->whereNull('deleted_at')
            ->get(['uri', 'collection', 'rkey']);

        foreach ($held as $row) {
            if (! isset($present[$row->uri])) {
                $events[] = new RecordEvent(0, $did, $row->collection, $row->rkey, RecordAction::Delete, null, $commit->rev, null, false, space: (string) $space);
            }
        }

        // getRepo has checked these records add up to the commit.
        $this->write($space, $did, $commit->rev, RepoCommit::fromRecords($records), $events);

        return $commit->rev;
    }

    /**
     * Write a repo's records and where it now stands, together.
     *
     * @param  array<int, RecordEvent>  $events
     */
    protected function write(SpaceRef $space, string $did, string $rev, RepoCommit $hash, array $events): void
    {
        $this->connection()->transaction(function (Connection $connection) use ($space, $did, $rev, $hash, $events) {
            if ($events !== []) {
                (new Pipeline($this->container, [$this->container->make(SpaceRecordIndexer::class)]))->run(new Batch($events));
            }

            $connection->table(self::REPOS)->upsert([[
                'space' => (string) $space,
                'did' => $did,
                'repo_rev' => $rev,
                'state' => base64_encode($hash->setHash->state()),
                'synced_at' => $this->now(),
            ]], ['space', 'did'], ['repo_rev', 'state', 'synced_at']);
        });
    }

    /**
     * The set hash kept for a repo, empty for one never synced.
     */
    protected function hash(?object $repo): RepoCommit
    {
        $state = is_string($repo?->state) ? base64_decode($repo->state, true) : false;

        return RepoCommit::fromState($state === false ? null : $state);
    }

    protected function event(SpaceRef $space, string $did, RepoOp $op, Commit $commit): RecordEvent
    {
        $action = match (true) {
            $op->isDelete() => RecordAction::Delete,
            $op->isCreate() => RecordAction::Create,
            default => RecordAction::Update,
        };

        return new RecordEvent(0, $did, $op->collection, $op->rkey, $action, $op->cid, $op->rev ?? $commit->rev, $op->isDelete() ? null : $op->value, true, space: (string) $space);
    }

    /**
     * A reader on the space's stored credential, or null, with the space
     * marked stale, when there is none still good.
     */
    protected function reader(SpaceRef $space, object $row, bool $markStale = true): ?SpaceReader
    {
        $credential = is_string($row->credential_did) ? $this->credentials->get($space, $row->credential_did) : null;

        if ($credential === null || $credential->isExpired((int) config('atp-spaces.credentials.leeway', 30))) {
            if ($markStale) {
                $this->stale($space, $row, 'No usable credential is stored for '.($row->credential_did ?? 'any account').'.');
            }

            return null;
        }

        return $this->spaces->readerFor($credential);
    }

    /**
     * Register for the space's notifications, or renew the registration
     * once it has less than notify.renew_before seconds left. A failure is
     * reported, and does not stop the sync.
     */
    protected function register(SpaceRef $space, SpaceReader $reader, object $row): void
    {
        if (! config('atp-appview.spaces.notify.enabled', true)) {
            return;
        }

        $renewBefore = (int) config('atp-appview.spaces.notify.renew_before', 21600);

        if ($row->notify_expires_at !== null && Carbon::parse($row->notify_expires_at, 'UTC')->subSeconds($renewBefore)->isFuture()) {
            return;
        }

        try {
            $expires = $reader->registerNotify($this->service());
        } catch (CredentialException $e) {
            throw $e;
        } catch (SpacesException $e) {
            report($e);

            return;
        }

        $this->connection()->table(self::SPACES)->where('id', $row->id)->update([
            'notify_expires_at' => Carbon::instance($expires)->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The authority refused the credential: the space is gone, or the
     * credential no longer works and the space waits for another.
     */
    protected function refused(SpaceRef $space, CredentialException $e): bool
    {
        if ($e->isSpaceDeleted()) {
            $this->deleted($space);

            return false;
        }

        if (($row = $this->state($space)) !== null) {
            $this->stale($space, $row, $e->getMessage());
        }

        return false;
    }

    protected function stale(SpaceRef $space, object $row, string $reason): void
    {
        $this->connection()->table(self::SPACES)->where('space', (string) $space)->update([
            'stale_at' => $row->stale_at ?? $this->now(),
            'last_error' => $reason,
        ]);
    }

    protected function failed(SpaceRef $space, Throwable $e): void
    {
        $this->connection()->table(self::SPACES)->where('space', (string) $space)->update([
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
        ]);
    }

    protected function service(): ?string
    {
        $service = config('atp-appview.spaces.notify.service');

        return is_string($service) && $service !== '' ? $service : null;
    }

    protected function pageSize(): int
    {
        return max(1, (int) config('atp-appview.spaces.page_size', 100));
    }

    protected function now(): string
    {
        return Carbon::now('UTC')->format('Y-m-d H:i:s');
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
