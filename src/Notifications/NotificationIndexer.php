<?php

namespace SocialDept\AtpAppview\Notifications;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Index\ValueHash;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Notifications\Contracts\Notifier;
use SocialDept\AtpAppview\Notifications\Events\NotificationCreated;

/**
 * Keeps atp_notifications in step with the records that notify, for the
 * collections in atp-appview.notifications.reasons and those the notifiers
 * read.
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
        $notifiers = Notifications::notifiers();

        if ($reasons === [] && $notifiers === []) {
            return;
        }

        $uris = [];
        $live = [];

        foreach ($batch->records() as $event) {
            if (! isset($reasons[$event->collection]) && ! isset($notifiers[$event->collection])) {
                continue;
            }

            $uris[$event->uri()] = true;

            // Only records arriving as they happen are offered to notifiers.
            if (isset($notifiers[$event->collection]) && $event->live && $event->action !== RecordAction::Delete) {
                $live[$event->uri()] = true;
            }
        }

        if ($uris === []) {
            return;
        }

        $connection = $this->db->connection(config('atp-appview.index.connection'));
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $connection->transaction(function () use ($connection, $reasons, $notifiers, $uris, $live, $now) {
            foreach (array_chunk(array_keys($uris), 500) as $chunk) {
                $this->sync($connection, $reasons, $chunk, $now, $notifiers, array_keys(array_intersect_key($live, array_flip($chunk))));
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
     * @param  Carbon|null  $since  Only records indexed at or after this time
     * @param  Carbon|null  $until  Only records indexed before this time
     * @return int The notifications added
     */
    public function build(?string $collection = null, ?\Closure $progress = null, ?Carbon $since = null, ?Carbon $until = null): int
    {
        $reasons = Notifications::reasons();
        $collections = $collection === null ? array_keys($reasons) : array_intersect([$collection], array_keys($reasons));
        $connection = $this->db->connection(config('atp-appview.index.connection'));
        $before = $connection->table('atp_notifications')->count();

        $this->chunks($connection, $collections, $since, $until, $progress, fn (array $uris) => $this->sync($connection, $reasons, $uris, null));

        return $connection->table('atp_notifications')->count() - $before;
    }

    /**
     * Run a notifier over records already indexed in its collections, or only
     * the one given, for a notifier added or changed after they arrived. It
     * need not be configured. Only adds: what a record gave before stays, so
     * running it twice, or over records it already notified for live, adds
     * nothing. Each notification takes its record's indexed time, and
     * NotificationCreated is dispatched only when announce is true.
     *
     * @param  (\Closure(int): void)|null  $progress  Called with the records read so far
     * @param  Carbon|null  $since  Only records indexed at or after this time
     * @param  Carbon|null  $until  Only records indexed before this time
     * @return int The notifications added
     */
    public function replay(Notifier $notifier, ?string $collection = null, ?\Closure $progress = null, ?Carbon $since = null, ?Carbon $until = null, bool $announce = false): int
    {
        $collections = $collection === null ? $notifier->collections() : array_intersect([$collection], $notifier->collections());
        $connection = $this->db->connection(config('atp-appview.index.connection'));
        $before = $connection->table('atp_notifications')->count();

        $this->chunks($connection, array_values(array_unique($collections)), $since, $until, $progress, function (array $uris) use ($connection, $notifier, $announce) {
            $records = $connection->table('atp_records')
                ->whereIn('uri', $uris)
                ->get(['uri', 'did', 'collection', 'record', 'indexed_at', 'deleted_at', 'hollowed_at', 'hidden_at', 'moderated_at', 'limited_at'])
                ->keyBy('uri');
            $offered = $this->offered($records, $uris);
            $rows = [];

            foreach ($this->notified(array_fill_keys($notifier->collections(), [$notifier]), $offered) as $notify) {
                $record = $records[$notify->source];

                if ($notify->recipient !== $record->did) {
                    $rows[self::key($record->uri, $notify->recipient, $notify->reason, $notify->subject)] = $this->row($record, $notify->recipient, $notify->reason, $notify->subject, 'notifier', null);
                }
            }

            // The unique source key leaves alone any notification already
            // there, whoever gave it.
            $existing = $connection->table('atp_notifications')->whereIn('source_uri', array_keys($offered))->get(['source_uri', 'recipient_did', 'reason', 'subject']);

            foreach ($existing as $row) {
                unset($rows[self::key($row->source_uri, $row->recipient_did, $row->reason, $row->subject)]);
            }

            if ($rows === []) {
                return;
            }

            $connection->table('atp_notifications')->insertOrIgnore(array_values($rows));

            if ($announce) {
                $this->announce($connection, $rows, $records);
            }
        });

        return $connection->table('atp_notifications')->count() - $before;
    }

    /**
     * The uris of records not deleted in these collections, within the
     * window, 500 at a time, each chunk in a transaction.
     *
     * @param  array<int, string>  $collections
     * @param  \Closure(array<int, string>): void  $each
     */
    protected function chunks(Connection $connection, array $collections, ?Carbon $since, ?Carbon $until, ?\Closure $progress, \Closure $each): void
    {
        $read = 0;

        foreach ($collections as $name) {
            $connection->table('atp_records')
                ->where('collection', $name)
                ->whereNull('deleted_at')
                // A cold record's body is not fetched for a rebuild.
                ->whereNull('hollowed_at')
                ->when($since !== null, fn ($query) => $query->where('indexed_at', '>=', $since->clone()->utc()->format('Y-m-d H:i:s')))
                ->when($until !== null, fn ($query) => $query->where('indexed_at', '<', $until->clone()->utc()->format('Y-m-d H:i:s')))
                ->select('uri')
                ->orderBy('uri')
                ->chunk(500, function ($rows) use ($connection, $each, $progress, &$read) {
                    $connection->transaction(fn () => $each($rows->pluck('uri')->all()));
                    $read += $rows->count();

                    if ($progress !== null) {
                        $progress($read);
                    }
                });
        }
    }

    /**
     * @param  array<string, array<string, string>>  $reasons
     * @param  array<int, string>  $uris
     * @param  string|null  $now  Null when building, which takes each record's indexed time and announces nothing
     * @param  array<string, array<int, Notifier>>  $notifiers  By collection
     * @param  array<int, string>  $live  The uris offered to notifiers
     */
    protected function sync(Connection $connection, array $reasons, array $uris, ?string $now, array $notifiers = [], array $live = []): void
    {
        $records = $connection->table('atp_records')
            ->whereIn('uri', $uris)
            ->get(['uri', 'did', 'collection', 'record', 'indexed_at', 'deleted_at', 'hollowed_at', 'hidden_at', 'moderated_at', 'limited_at'])
            ->keyBy('uri');

        $want = [];

        foreach ($records as $record) {
            if ($record->deleted_at !== null || $record->record === null || $record->hollowed_at !== null) {
                continue;
            }

            $value = is_array($record->record) ? $record->record : (array) json_decode((string) $record->record, true);

            foreach ($reasons[$record->collection] ?? [] as $path => $reason) {
                foreach (self::targets(data_get($value, $path)) as [$recipient, $subject]) {
                    if ($recipient !== $record->did) {
                        $want[self::key($record->uri, $recipient, $reason, $subject)] = $this->row($record, $recipient, $reason, $subject, 'reason', $now);
                    }
                }
            }
        }

        $offered = $this->offered($records, $live);

        foreach ($this->notified($notifiers, $offered) as $notify) {
            $record = $records[$notify->source];
            $key = self::key($record->uri, $notify->recipient, $notify->reason, $notify->subject);

            if ($notify->recipient !== $record->did && ! isset($want[$key])) {
                $want[$key] = $this->row($record, $notify->recipient, $notify->reason, $notify->subject, 'notifier', $now);
            }
        }

        $have = [];
        $gone = [];

        foreach ($connection->table('atp_notifications')->whereIn('source_uri', $uris)->get(['id', 'source_uri', 'recipient_did', 'reason', 'subject', 'origin']) as $row) {
            $key = self::key($row->source_uri, $row->recipient_did, $row->reason, $row->subject);
            $record = $records[$row->source_uri] ?? null;

            // A configured reason is kept while the record still gives it, a
            // notifier's while the record was not offered again, and a sent
            // one while its record exists. A cold record's body is the one
            // its notifications were built from, so they all stay.
            $kept = $record !== null && $record->deleted_at === null && ($record->hollowed_at !== null || $record->record !== null && match ($row->origin) {
                'notifier' => isset($offered[$row->source_uri]) ? isset($want[$key]) : true,
                'sent' => true,
                default => isset($want[$key]),
            });

            if ($kept) {
                $have[$key] = true;
            } else {
                $gone[] = (int) $row->id;
            }
        }

        if ($gone !== []) {
            $connection->table('atp_notifications')->whereIn('id', $gone)->delete();
        }

        $new = array_diff_key($want, $have);

        if ($new === []) {
            return;
        }

        $connection->table('atp_notifications')->insert(array_values($new));

        if ($now !== null) {
            $this->announce($connection, $new, $records);
        }
    }

    /**
     * Dispatch NotificationCreated after commit for these new rows, by key,
     * only when their record shows: not one hidden, moderated or needing
     * review.
     *
     * @param  array<string, array<string, mixed>>  $new
     * @param  Collection<string, object>  $records
     */
    protected function announce(Connection $connection, array $new, Collection $records): void
    {
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
     * The live records notifiers are offered, by uri: those still stored.
     *
     * @param  Collection<string, object>  $records
     * @param  array<int, string>  $live
     * @return array<string, true>
     */
    protected function offered(Collection $records, array $live): array
    {
        $offered = [];

        foreach ($live as $uri) {
            $record = $records[$uri] ?? null;

            if ($record !== null && $record->deleted_at === null && $record->record !== null) {
                $offered[$uri] = true;
            }
        }

        return $offered;
    }

    /**
     * What the notifiers give for the offered records, each notifier with the
     * records of its collections. A notification for any other record is
     * dropped.
     *
     * @param  array<string, array<int, Notifier>>  $notifiers
     * @param  array<string, true>  $offered
     * @return array<int, Notify>
     */
    protected function notified(array $notifiers, array $offered): array
    {
        if ($offered === []) {
            return [];
        }

        $models = Record::query()->whereIn('uri', array_keys($offered))->get();
        $given = [];

        $unique = [];

        foreach (array_merge(...array_values($notifiers)) as $notifier) {
            $unique[spl_object_id($notifier)] = $notifier;
        }

        foreach ($unique as $notifier) {
            $mine = $models->filter(fn (Record $record) => in_array($notifier, $notifiers[$record->collection] ?? [], true))->values()->all();

            if ($mine === []) {
                continue;
            }

            foreach ($notifier->notify($mine) as $notify) {
                if ($notify instanceof Notify && isset($offered[$notify->source])) {
                    $given[] = $notify;
                }
            }
        }

        return $given;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(object $record, string $recipient, string $reason, string $subject, string $origin, ?string $now): array
    {
        return [
            'recipient_did' => $recipient,
            'reason' => $reason,
            'subject' => $subject,
            'group_key' => Notifications::groupKey($reason, $subject, $record->uri),
            'actor_did' => $record->did,
            'actor_hash' => ValueHash::of($record->did),
            'source_uri' => $record->uri,
            'indexed_at' => $now ?? Carbon::parse($record->indexed_at, 'UTC')->format('Y-m-d H:i:s'),
            'origin' => $origin,
        ];
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
