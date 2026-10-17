<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;

/**
 * Writes every ingested batch to atp_records and atp_identities.
 *
 * Writes are idempotent, so a redelivered batch changes nothing:
 *
 * - A record is only overwritten by an event with the same or a newer rev.
 * - A delete becomes a tombstone carrying the delete's rev, so an older
 *   create replayed during a backfill cannot bring the record back.
 * - Several events for one record in a batch collapse to the last, which Tap's
 *   per-repo ordering makes the newest.
 */
class RecordIndexer implements BatchHandler
{
    public function __construct(
        protected ConnectionResolverInterface $db,
    ) {
    }

    public function handle(Batch $batch): void
    {
        $clock = Carbon::now('UTC');
        $now = $clock->format('Y-m-d H:i:s');
        $records = $this->recordRows($batch->records(), $now, $clock);
        $identities = $this->identityRows($batch->identities(), $now);

        if ($records === [] && $identities === []) {
            return;
        }

        $connection = $this->db->connection(config('atp-appview.index.connection'));

        $connection->transaction(function () use ($connection, $records, $identities) {
            if ($records !== []) {
                (new RecordWriter($connection))->upsert($records);
            }

            if ($identities !== []) {
                $connection->table('atp_identities')->upsert($identities, ['did'], ['handle', 'active', 'status', 'updated_at']);
            }
        });
    }

    /**
     * @param  array<int, RecordEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    protected function recordRows(array $events, string $now, Carbon $clock): array
    {
        $rows = [];

        foreach ($events as $event) {
            $deleted = $event->action === RecordAction::Delete;

            // Keyed by uri, so a later event for the same record replaces an
            // earlier one in the same batch.
            $rows[$event->uri()] = [
                'uri' => $event->uri(),
                'did' => $event->did,
                'collection' => $event->collection,
                'rkey' => $event->rkey,
                'cid' => $deleted ? null : $event->cid,
                'record' => $deleted || $event->record === null ? null : json_encode($event->record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'indexed_at' => $now,
                'sort_at' => SortAt::for($deleted ? null : $event->record, $event->rkey, $clock),
                'deleted_at' => $deleted ? $now : null,
                'rev' => $event->rev,
            ];
        }

        return array_values($rows);
    }

    /**
     * @param  array<int, IdentityEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    protected function identityRows(array $events, string $now): array
    {
        $rows = [];

        foreach ($events as $event) {
            $rows[$event->did] = [
                'did' => $event->did,
                'handle' => $event->handle,
                'active' => $event->active,
                'status' => $event->status,
                'updated_at' => $now,
            ];
        }

        return array_values($rows);
    }
}
