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
 *
 * A record an ingest repair rewrote keeps its repairs and the record as it
 * is in the repo in the repair column, which is null for every other record.
 *
 * Records are checked against their lexicon first when
 * atp-appview.index.validate asks for it, through RecordValidation.
 *
 * An account event changes its records too, through AccountStatus: a deleted
 * account's records are tombstoned, an inactive account's hidden.
 */
class RecordIndexer implements BatchHandler
{
    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ?RecordValidation $validation = null,
    ) {
    }

    public function handle(Batch $batch): void
    {
        $clock = Carbon::now('UTC');
        $now = $clock->format('Y-m-d H:i:s');
        $events = RecordValidation::mode() === RecordValidation::OFF ? $batch->records() : $this->validation()->filter($batch->records());
        $records = $this->recordRows($events, $now, $clock);
        $identities = $this->identityRows($batch->identities(), $now);

        if ($records === [] && $identities === []) {
            return;
        }

        $connection = $this->db->connection(config('atp-appview.index.connection'));

        $connection->transaction(function () use ($connection, $records, $identities, $now) {
            $status = new AccountStatus($connection);

            if ($records !== []) {
                (new RecordWriter($connection))->upsert($records);
            }

            if ($identities !== []) {
                $connection->table('atp_identities')->upsert($identities, ['did'], ['handle', 'active', 'status', 'updated_at']);

                $status->apply(array_column(array_map(fn (array $row) => [
                    'did' => $row['did'],
                    'account' => ['active' => (bool) $row['active'], 'status' => $row['status']],
                ], $identities), 'account', 'did'));
            }

            if ($records !== []) {
                $status->hideArrivals(array_column($records, 'did'));
                // After hiding, so new references of an inactive account are hidden too.
                app(RecordValues::class)->rewrite($connection, $records, $now);
            }
        });
    }

    protected function validation(): RecordValidation
    {
        return $this->validation ??= app(RecordValidation::class);
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
                'record' => $deleted || $event->record === null ? null : json_encode(self::withoutNullCharacters($event->record), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'repair' => $deleted || $event->repairedBy === [] ? null : json_encode(['by' => $event->repairedBy, 'original' => self::withoutNullCharacters($event->original ?? [])], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'indexed_at' => $now,
                'sort_at' => SortAt::for($deleted ? null : $event->record, $event->rkey, $clock, self::sortFields($event->collection)),
                'deleted_at' => $deleted ? $now : null,
                'rev' => $event->rev,
            ];
        }

        return array_values($rows);
    }

    /**
     * The record fields that say when a record in this collection was made,
     * in order: atp-appview.index.sort_fields, else createdAt.
     *
     * @return array<int, string>
     */
    public static function sortFields(string $collection): array
    {
        // Read whole: NSIDs contain dots, which config() would take as nesting.
        $map = (array) config('atp-appview.index.sort_fields', []);

        return array_values((array) ($map[$collection] ?? $map['*'] ?? ['createdAt']));
    }

    /**
     * The record with every NUL character, in keys and string values,
     * replaced with U+FFFD. Postgres cannot extract a field from a json value
     * holding an escaped NUL, nor store one as jsonb, so one such record would
     * break queries across the table or fail its batch forever. The stored
     * value then no longer matches the record's CID, for those few records.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function withoutNullCharacters(array $value): array
    {
        $clean = [];

        foreach ($value as $key => $item) {
            $key = is_string($key) ? str_replace("\0", "\u{FFFD}", $key) : $key;

            $clean[$key] = match (true) {
                is_string($item) => str_replace("\0", "\u{FFFD}", $item),
                is_array($item) => self::withoutNullCharacters($item),
                default => $item,
            };
        }

        return $clean;
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
