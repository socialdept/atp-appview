<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Blocklist\PdsLookup;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSupport\Facades\Resolver;

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
     * @throws RuntimeException when the PDS cannot be read
     */
    public function reconcile(string $did, bool $dryRun = false): ReconcileResult
    {
        $host = $this->pdsLookup->forDids([$did])[$did] ?? null;

        if ($this->blocklist->blocksRepo($did, $host)) {
            return new ReconcileResult($did, skipped: 'blocked');
        }

        $collections = array_values(array_filter(
            (array) config('atp-appview.ingest.collections', []),
            fn (string $collection) => $this->blocklist->ruleFor($did, $collection, $host) === null,
        ));

        if ($collections === []) {
            return new ReconcileResult($did, skipped: 'no collections');
        }

        $pds = Atp::public(Resolver::resolvePds($did) ?? throw new RuntimeException("{$did} has no PDS."));

        // The revision first: everything listed after it is at least that
        // new, and anything indexed past it is left alone.
        $rev = $pds->atproto->sync->getLatestCommit($did)->rev;

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
        );
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
