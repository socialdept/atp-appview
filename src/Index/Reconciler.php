<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Blocklist\PdsLookup;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Collections;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpClient\Exceptions\AtpResponseException;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSupport\Facades\Resolver;
use Throwable;

/**
 * Brings one repo's indexed records in line with its own PDS.
 *
 * Tap's resync walks a repo export and sends only the records whose CID it
 * has not seen, and never a delete, so a record removed while Tap was not
 * following the repo stays indexed for ever. Reconciling lists the repo's
 * records in every indexed collection and compares:
 *
 * - An indexed record the PDS no longer has is tombstoned.
 * - A record the PDS has but the index lacks, or holds at another CID, is
 *   indexed.
 *
 * The repo's revision is read before listing, and every write carries it, so
 * the usual guard applies: nothing indexed at a newer revision, such as a
 * record created or deleted while the listing ran, is touched. A listing that
 * fails or runs past the record limit writes nothing, since a partial listing
 * would read as deletes.
 *
 * The PDS also says whether the account still exists. A repo it cannot find
 * is a deleted account, whose records are tombstoned. A deactivated, taken
 * down or suspended repo has its records hidden instead, and a deactivated
 * account the PDS serves again is shown again. See AccountStatus.
 */
class Reconciler
{
    public function __construct(
        protected ConnectionResolverInterface $db,
        protected RecordIndexer $indexer,
        protected Blocklist $blocklist,
        protected PdsLookup $pdsLookup,
    ) {
    }

    /**
     * Reconcile a repo and keep the outcome on its identity, as the scheduled
     * appview:reconcile does: when it was checked, the revision it compared
     * against, and the error if the PDS could not be read.
     *
     * @throws Throwable when the PDS cannot be read, after recording why
     */
    public function reconcileAndRecord(string $did): ReconcileResult
    {
        try {
            $result = $this->reconcile($did);
        } catch (Throwable $e) {
            $this->record($did, null, $e->getMessage());

            throw $e;
        }

        $this->record($did, $result->rev, null);

        return $result;
    }

    /**
     * @throws RuntimeException when the PDS cannot be read
     */
    public function reconcile(string $did, bool $dryRun = false): ReconcileResult
    {
        $identity = Identity::query()->find($did, ['did', 'active', 'status']);

        // A takedown or suspension may be the relay's, which the PDS cannot
        // see, so only Tap lifts it.
        if ($identity !== null && ! $identity->active && ! in_array($identity->status, AccountStatus::LIFTED_BY_PDS, true)) {
            return new ReconcileResult($did, skipped: $identity->status ?? 'inactive');
        }

        $host = $this->pdsLookup->forDids([$did])[$did] ?? null;

        if ($this->blocklist->blocksRepo($did, $host)) {
            return new ReconcileResult($did, skipped: 'blocked');
        }

        $collections = array_values(array_filter(
            Collections::all(),
            fn (string $collection) => $this->blocklist->ruleFor($did, $collection, $host) === null,
        ));

        if ($collections === []) {
            return new ReconcileResult($did, skipped: 'no collections');
        }

        // The revision first: everything listed after it is at least that
        // new, and anything indexed past it is left alone.
        ['pds' => $pds, 'rev' => $rev, 'status' => $status] = $this->latestCommit($did);

        if ($status !== null) {
            if (! $dryRun) {
                $this->status()->set([$did => ['active' => false, 'status' => $status]]);
            }

            return new ReconcileResult($did, account: $status);
        }

        $reactivated = $identity !== null && ! $identity->active;

        if ($reactivated && ! $dryRun) {
            $this->status()->set([$did => ['active' => true, 'status' => 'active']]);
        }

        // Only collections the repo has need listing. One it lacks holds
        // nothing, so whatever is indexed there is gone.
        $present = $pds->atproto->repo->describeRepo($did)->collections;
        $listed = [];

        foreach ($collections as $collection) {
            $listed[$collection] = in_array($collection, $present, true) ? $this->list($pds, $did, $collection) : [];
        }

        $events = [];
        $indexed = [];
        $tombstoned = [];
        $connection = $this->db->connection(config('atp-appview.index.connection'));

        foreach ($listed as $collection => $records) {
            $rows = $connection->table('atp_records')
                ->where('did', $did)
                ->where('collection', $collection)
                ->get(['uri', 'rkey', 'cid', 'rev', 'deleted_at'])
                ->keyBy('uri');

            foreach ($records as $uri => $record) {
                $row = $rows[$uri] ?? null;
                $current = $row !== null && $row->deleted_at === null && $row->cid === $record['cid'];

                if (! $current && ($row === null || strcmp((string) $row->rev, $rev) <= 0)) {
                    $events[] = $this->event($did, $collection, $record['rkey'], RecordAction::Update, $record['cid'], $rev, $record['value']);
                    $indexed[] = $uri;
                }
            }

            foreach ($rows as $uri => $row) {
                if ($row->deleted_at === null && ! isset($records[$uri]) && strcmp((string) $row->rev, $rev) <= 0) {
                    $events[] = $this->event($did, $collection, $row->rkey, RecordAction::Delete, null, $rev, null);
                    $tombstoned[] = $uri;
                }
            }
        }

        if (! $dryRun && $events !== []) {
            $this->indexer->handle(new Batch($events));
        }

        return new ReconcileResult(
            did: $did,
            rev: $rev,
            listed: array_sum(array_map('count', $listed)),
            indexed: $indexed,
            tombstoned: $tombstoned,
            account: $reactivated ? 'reactivated' : null,
        );
    }

    /**
     * The repo's PDS client and latest revision, or the account status the
     * PDS answered instead.
     *
     * A repo its PDS cannot find may have moved, and the DID document cached
     * here still names the old PDS, so the PDS is resolved again before the
     * account is taken as deleted.
     *
     * @return array{pds: mixed, rev: string, status: null}|array{pds: null, rev: null, status: string}
     */
    protected function record(string $did, ?string $rev, ?string $error): void
    {
        Identity::query()->whereKey($did)->update([
            'reconciled_at' => Carbon::now('UTC'),
            'reconciled_rev' => $rev,
            'reconcile_error' => $error === null ? null : Str::limit($error, 1000),
        ]);
    }

    protected function latestCommit(string $did, bool $fresh = false): array
    {
        $pds = Atp::public(Resolver::resolvePds($did, ! $fresh) ?? throw new RuntimeException("{$did} has no PDS."));

        try {
            return ['pds' => $pds, 'rev' => $pds->atproto->sync->getLatestCommit($did)->rev, 'status' => null];
        } catch (AtpResponseException $e) {
            $status = match ($e->errorCode) {
                'RepoNotFound' => AccountStatus::DELETED,
                'RepoDeactivated' => 'deactivated',
                'RepoTakendown' => 'takendown',
                'RepoSuspended' => 'suspended',
                default => throw $e,
            };

            if ($status === AccountStatus::DELETED && ! $fresh) {
                return $this->latestCommit($did, fresh: true);
            }

            return ['pds' => null, 'rev' => null, 'status' => $status];
        }
    }

    protected function status(): AccountStatus
    {
        return new AccountStatus($this->db->connection(config('atp-appview.index.connection')));
    }

    /**
     * Every record in one collection of the repo, keyed by uri.
     *
     * @return array<string, array{rkey: string, cid: string, value: array<string, mixed>}>
     */
    protected function list(mixed $pds, string $did, string $collection): array
    {
        $max = max(1, (int) config('atp-appview.index.reconcile.max_records', 10000));
        $records = [];
        $cursor = null;

        do {
            $page = $pds->atproto->repo->listRecords($did, $collection, 100, $cursor);

            foreach ($page->records as $record) {
                $uri = $record['uri'] ?? null;

                if (! is_string($uri) || ! is_string($record['cid'] ?? null) || ! is_array($record['value'] ?? null)) {
                    throw new RuntimeException("The PDS listed a malformed record in {$collection}.");
                }

                $prefix = "at://{$did}/{$collection}/";

                if (! str_starts_with($uri, $prefix)) {
                    throw new RuntimeException("The PDS listed {$uri}, which is not in {$did}'s {$collection}.");
                }

                $records[$uri] = ['rkey' => substr($uri, strlen($prefix)), 'cid' => $record['cid'], 'value' => $record['value']];
            }

            if (count($records) > $max) {
                throw new RuntimeException("{$collection} holds more than {$max} records, past atp-appview.index.reconcile.max_records.");
            }

            // A cursor that does not move would page for ever.
            $next = $page->cursor;
            $cursor = $next !== null && $next !== $cursor && $page->records->isNotEmpty() ? $next : null;
        } while ($cursor !== null);

        return $records;
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    protected function event(string $did, string $collection, string $rkey, RecordAction $action, ?string $cid, string $rev, ?array $record): RecordEvent
    {
        return new RecordEvent(0, $did, $collection, $rkey, $action, $cid, $rev, $record, false);
    }
}
