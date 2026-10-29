<?php

namespace SocialDept\AtpAppview\Spaces;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Index\RecordValidation;
use SocialDept\AtpAppview\Index\RecordWriter;
use SocialDept\AtpAppview\Index\SortAt;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Spaces\Events\SpaceRecordIndexed;
use SocialDept\AtpAppview\Spaces\Events\SpaceRecordRemoved;

/**
 * Writes the records of a space sync to atp_space_records, the way
 * RecordIndexer writes public records to atp_records: through the same
 * revision guard, with deletes as tombstones and repairs kept, and their
 * indexed field values to atp_space_record_values.
 *
 * It takes only events carrying a space, and RecordIndexer never runs on
 * a space sync, so a space record never reaches atp_records. Live changes
 * are announced as SpaceRecordIndexed and SpaceRecordRemoved, never as
 * RecordIndexed and RecordRemoved.
 */
class SpaceRecordIndexer implements BatchHandler
{
    /**
     * RecordWriter's columns with the space beside the uri. cid and rev stay
     * last, as RecordWriter needs.
     */
    public const COLUMNS = ['uri', 'space', 'did', 'collection', 'rkey', 'record', 'repair', 'indexed_at', 'sort_at', 'deleted_at', 'cid', 'rev'];

    public const TABLE = 'atp_space_records';

    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ?RecordValidation $validation = null,
    ) {
    }

    public function handle(Batch $batch): void
    {
        $clock = Carbon::now('UTC');
        $events = array_values(array_filter($batch->records(), fn (RecordEvent $event) => $event->space !== null));

        if (RecordValidation::mode() !== RecordValidation::OFF) {
            $events = $this->validation()->filter($events);
        }

        $now = $clock->format('Y-m-d H:i:s');
        $rows = $this->rows($events, $now, $clock);

        if ($rows === []) {
            return;
        }

        $connection = $this->db->connection(config('atp-appview.index.connection'));
        $live = array_values(array_filter($events, fn (RecordEvent $event) => $event->live));

        // What live deletes held, read before they are cleared.
        $deleted = array_values(array_map(fn (RecordEvent $event) => $event->uri(), array_filter($live, fn (RecordEvent $event) => $event->action === RecordAction::Delete)));
        $previous = $deleted === [] ? [] : $connection->table(self::TABLE)->whereIn('uri', $deleted)->whereNotNull('record')->pluck('record', 'uri')->all();

        (new RecordWriter($connection, self::TABLE, self::COLUMNS))->upsert($rows);
        app(SpaceRecordValues::class)->rewrite($connection, $rows, $now);

        if ($live !== []) {
            $connection->afterCommit(fn () => $this->announce($live, $previous));
        }
    }

    /**
     * @param  array<int, RecordEvent>  $events
     * @param  array<string, string>  $previous  Deleted records' JSON, by uri
     */
    protected function announce(array $events, array $previous): void
    {
        foreach ($events as $event) {
            event($event->action === RecordAction::Delete
                ? new SpaceRecordRemoved((string) $event->space, $event->uri(), $event->did, $event->collection, $event->rkey, (array) json_decode((string) ($previous[$event->uri()] ?? '[]'), true))
                : new SpaceRecordIndexed((string) $event->space, $event->uri(), $event->did, $event->collection, $event->rkey, $event->action, $event->record ?? []));
        }
    }

    protected function validation(): RecordValidation
    {
        return $this->validation ??= app(RecordValidation::class);
    }

    /**
     * Rows as RecordIndexer builds them, with the space.
     *
     * @param  array<int, RecordEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    protected function rows(array $events, string $now, Carbon $clock): array
    {
        $rows = [];

        foreach ($events as $event) {
            $deleted = $event->action === RecordAction::Delete;

            $rows[$event->uri()] = [
                'uri' => $event->uri(),
                'space' => $event->space,
                'did' => $event->did,
                'collection' => $event->collection,
                'rkey' => $event->rkey,
                'cid' => $deleted ? null : $event->cid,
                'record' => $deleted || $event->record === null ? null : json_encode(RecordIndexer::withoutNullCharacters($event->record), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'repair' => $deleted || $event->repairedBy === [] ? null : json_encode(['by' => $event->repairedBy, 'original' => RecordIndexer::withoutNullCharacters($event->original ?? [])], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'indexed_at' => $now,
                'sort_at' => SortAt::for($deleted ? null : $event->record, $event->rkey, $clock, RecordIndexer::sortFields($event->collection)),
                'deleted_at' => $deleted ? $now : null,
                'rev' => $event->rev,
            ];
        }

        return array_values($rows);
    }
}
