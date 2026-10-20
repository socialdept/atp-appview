<?php

namespace SocialDept\AtpAppview\Notifications;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Notifications\Events\NotificationCreated;

/**
 * Keeps atp_notifications in step with the records that notify, for the
 * collections in atp-appview.notifications.reasons.
 *
 * Runs after RecordIndexer and reads each record as it was stored, so a
 * replayed older version, a rejected record or a delete leaves what the index
 * holds. A record's notifications are brought to what it says now: new ones
 * added, those it no longer gives removed, the rest left alone with their
 * time.
 *
 * NotificationCreated is dispatched after commit for each new notification
 * whose record is visible.
 */
class NotificationIndexer implements BatchHandler
{
    public function __construct(
        protected ConnectionResolverInterface $db,
    ) {
    }

    public function handle(Batch $batch): void
    {
        $reasons = Notifications::reasons();

        if ($reasons === []) {
            return;
        }

        $uris = [];

        foreach ($batch->records() as $event) {
            if (isset($reasons[$event->collection])) {
                $uris[$event->uri()] = true;
            }
        }

        if ($uris === []) {
            return;
        }

        $connection = $this->db->connection(config('atp-appview.index.connection'));
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $connection->transaction(function () use ($connection, $reasons, $uris, $now) {
            foreach (array_chunk(array_keys($uris), 500) as $chunk) {
                $this->sync($connection, $reasons, $chunk, $now);
            }
        });
    }

    /**
     * Notifications for records already indexed, in the collections with
     * reasons, or only the one given: for reasons added after their records
     * arrived. Nothing is announced, and each notification takes its record's
     * indexed time.
     *
     * @param  (\Closure(int): void)|null  $progress  Called with the records read so far
     * @return int The notifications added
     */
    public function build(?string $collection = null, ?\Closure $progress = null): int
    {
        $reasons = Notifications::reasons();
        $collections = $collection === null ? array_keys($reasons) : array_intersect([$collection], array_keys($reasons));
        $connection = $this->db->connection(config('atp-appview.index.connection'));
        $before = $connection->table('atp_notifications')->count();
        $read = 0;

        foreach ($collections as $name) {
            $connection->table('atp_records')
                ->where('collection', $name)
                ->whereNull('deleted_at')
                ->select('uri')
                ->orderBy('uri')
                ->chunk(500, function ($rows) use ($connection, $reasons, $progress, &$read) {
                    $connection->transaction(fn () => $this->sync($connection, $reasons, $rows->pluck('uri')->all(), null));
                    $read += $rows->count();

                    if ($progress !== null) {
                        $progress($read);
                    }
                });
        }

        return $connection->table('atp_notifications')->count() - $before;
    }

    /**
     * @param  array<string, array<string, string>>  $reasons
     * @param  array<int, string>  $uris
     * @param  string|null  $now  Null when building, which takes each record's indexed time and announces nothing
     */
    protected function sync(Connection $connection, array $reasons, array $uris, ?string $now): void
    {
        $records = $connection->table('atp_records')
            ->whereIn('uri', $uris)
            ->get(['uri', 'did', 'collection', 'record', 'indexed_at', 'deleted_at', 'hidden_at', 'moderated_at', 'limited_at'])
            ->keyBy('uri');

        $want = [];

        foreach ($records as $record) {
            if ($record->deleted_at !== null || $record->record === null) {
                continue;
            }

            $value = is_array($record->record) ? $record->record : (array) json_decode((string) $record->record, true);

            foreach ($reasons[$record->collection] ?? [] as $path => $reason) {
                foreach (self::targets(data_get($value, $path)) as [$recipient, $subject]) {
                    if ($recipient === $record->did) {
                        continue;
                    }

                    $want[self::key($record->uri, $recipient, $reason, $subject)] = [
                        'recipient_did' => $recipient,
                        'reason' => $reason,
                        'subject' => $subject,
                        'group_key' => Notifications::groupKey($reason, $subject, $record->uri),
                        'actor_did' => $record->did,
                        'source_uri' => $record->uri,
                        'indexed_at' => $now ?? Carbon::parse($record->indexed_at, 'UTC')->format('Y-m-d H:i:s'),
                    ];
                }
            }
        }

        $have = [];

        foreach ($connection->table('atp_notifications')->whereIn('source_uri', $uris)->get(['id', 'source_uri', 'recipient_did', 'reason', 'subject']) as $row) {
            $have[self::key($row->source_uri, $row->recipient_did, $row->reason, $row->subject)] = (int) $row->id;
        }

        if (($gone = array_values(array_diff_key($have, $want))) !== []) {
            $connection->table('atp_notifications')->whereIn('id', $gone)->delete();
        }

        $new = array_diff_key($want, $have);

        if ($new === []) {
            return;
        }

        $connection->table('atp_notifications')->insert(array_values($new));

        if ($now === null) {
            return;
        }

        // Announced only when their record shows: not one hidden, moderated
        // or needing review.
        $visible = array_filter($new, function (array $row) use ($records) {
            $record = $records[$row['source_uri']];

            return $record->hidden_at === null && $record->moderated_at === null && $record->limited_at === null;
        });

        if ($visible === []) {
            return;
        }

        $created = $connection->table('atp_notifications')
            ->whereIn('source_uri', array_unique(array_column($visible, 'source_uri')))
            ->get()
            ->filter(fn ($row) => isset($visible[self::key($row->source_uri, $row->recipient_did, $row->reason, $row->subject)]))
            ->map(fn ($row) => NotificationCreated::fromRow($row))
            ->values();

        $connection->afterCommit(fn () => $created->each(fn (NotificationCreated $event) => event($event)));
    }

    /**
     * The recipients a field's value notifies, with the subject: a DID
     * notifies itself, an AT-URI the account it belongs to.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function targets(mixed $value): array
    {
        $targets = [];

        foreach (Arr::flatten(Arr::wrap($value)) as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim($item);

            if (preg_match('#^did:[a-z]+:[a-zA-Z0-9._:%-]+$#', $item)) {
                $targets[$item] = [$item, $item];
            } elseif (preg_match('#^at://(did:[a-z]+:[a-zA-Z0-9._:%-]+)(/|$)#', $item, $match)) {
                $targets[$item] = [$match[1], $item];
            }
        }

        return array_values($targets);
    }

    protected static function key(string $source, string $recipient, string $reason, string $subject): string
    {
        return "{$source}\n{$recipient}\n{$reason}\n{$subject}";
    }
}
