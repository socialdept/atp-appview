<?php

namespace SocialDept\AtpAppview\Notifications;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Notifications\Contracts\Notifier;
use SocialDept\AtpAppview\Notifications\Events\NotificationCreated;
use SocialDept\AtpAppview\Profiles\Profiles;
use SocialDept\AtpAppview\Views\Hydrator;
use SocialDept\AtpAppview\Views\View;

/**
 * A recipient's notifications, grouped or one at a time, hydrated as they
 * would see them, with what they have seen.
 *
 * Only notifications whose record is visible are read: not deleted, not
 * hidden with its account, not hidden by moderation and not needing review.
 * Lifting an action brings them back.
 *
 * A group is every notification with the same reason and subject, newest
 * first by its newest notification's time. Times, not ids, order them, since
 * notifications built for older records arrive with those records' times:
 *
 *     ['groupKey' => '…', 'reason' => 'like', 'subject' => 'at://…',
 *      'subjectView' => [...], 'count' => 5, 'unreadCount' => 2,
 *      'isRead' => false, 'indexedAt' => '…',
 *      'latest' => [['uri' => '…', 'actor' => [...], 'view' => [...], 'indexedAt' => '…']]]
 */
class Notifications
{
    public const MAX_LIMIT = 100;

    /**
     * The configured reasons, by collection, then by field path.
     *
     * @return array<string, array<string, string>>
     */
    public static function reasons(): array
    {
        // Read whole: NSIDs contain dots, which config() would take as nesting.
        return array_filter(
            array_map(fn ($paths) => array_map('strval', (array) $paths), (array) config('atp-appview.notifications.reasons', [])),
            fn (array $paths) => $paths !== [],
        );
    }

    /**
     * The configured notifiers, by each collection they read.
     *
     * @return array<string, array<int, Notifier>>
     */
    public static function notifiers(): array
    {
        $byCollection = [];

        foreach ((array) config('atp-appview.notifications.notifiers', []) as $class) {
            $notifier = app($class);

            foreach ($notifier->collections() as $collection) {
                $byCollection[$collection][] = $notifier;
            }
        }

        return $byCollection;
    }

    /**
     * What notifications share a group by: the reason and subject, or for a
     * separate reason the notifying record too.
     */
    public static function groupKey(string $reason, string $subject, string $source): string
    {
        return in_array($reason, (array) config('atp-appview.notifications.separate', []), true)
            ? sha1("{$reason}\n{$source}")
            : sha1("{$reason}\n{$subject}");
    }

    /**
     * Notify someone of something no record says on its own, such as a
     * scheduled digest or a milestone. The source is the record it is about,
     * whose author is the actor and whose visibility it follows, and the
     * subject defaults to the recipient. Unlike a reason or a notifier, it
     * may notify the source's own author. Sending the same again adds
     * nothing, and deleting the source removes it.
     *
     * @return int|null The new notification, or null when it was already sent or the source is not indexed
     */
    public function send(string $recipient, string $reason, string $source, ?string $subject = null): ?int
    {
        $subject ??= $recipient;
        $record = $this->connection()->table('atp_records')->where('uri', $source)->whereNull('deleted_at')->first(['uri', 'did', 'hidden_at', 'moderated_at', 'limited_at']);

        if ($record === null) {
            return null;
        }

        $inserted = $this->connection()->table('atp_notifications')->insertOrIgnore([
            'recipient_did' => $recipient,
            'reason' => $reason,
            'subject' => $subject,
            'group_key' => self::groupKey($reason, $subject, $source),
            'actor_did' => $record->did,
            'source_uri' => $source,
            'indexed_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
            'origin' => 'sent',
        ]);

        if ($inserted === 0) {
            return null;
        }

        $row = $this->connection()->table('atp_notifications')
            ->where(['source_uri' => $source, 'recipient_did' => $recipient, 'reason' => $reason, 'subject' => $subject])
            ->first();

        if ($record->hidden_at === null && $record->moderated_at === null && $record->limited_at === null) {
            $this->connection()->afterCommit(fn () => event(NotificationCreated::fromRow($row)));
        }

        return (int) $row->id;
    }

    /**
     * A page of groups, newest first.
     *
     * @return array{notifications: array<int, array<string, mixed>>, cursor: string|null, seenAt: string|null}
     */
    public function groups(string $did, int $limit = 50, ?string $cursor = null): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $seen = $this->seenAt($did);

        $query = $this->visible($did)
            ->select('n.group_key', 'n.reason', 'n.subject')
            ->selectRaw('count(*) as total, max(n.id) as newest, max(n.indexed_at) as newest_at')
            ->selectRaw('sum(case when n.indexed_at > ? then 1 else 0 end) as unread', [$this->since($seen)])
            ->groupBy('n.group_key', 'n.reason', 'n.subject')
            ->orderByDesc('newest_at')
            ->orderByDesc('newest')
            ->limit($limit + 1);

        if (($after = self::parseCursor($cursor)) !== null) {
            $query->havingRaw('(max(n.indexed_at) < ? or (max(n.indexed_at) = ? and max(n.id) < ?))', [$after[0], $after[0], $after[1]]);
        }

        $groups = $query->get();
        $more = $groups->count() > $limit;
        $groups = $groups->take($limit);
        $latest = $this->latest($did, $groups->pluck('group_key')->all());

        $views = $this->views([
            ...$groups->pluck('subject')->all(),
            ...$latest->pluck('source_uri')->all(),
        ], $did);
        $profiles = app(Profiles::class)->basic($latest->pluck('actor_did')->all());

        $items = $groups->map(fn ($group) => [
            'groupKey' => $group->group_key,
            'reason' => $group->reason,
            'subject' => $group->subject,
            'subjectView' => $views[$group->subject] ?? null,
            'count' => (int) $group->total,
            'unreadCount' => (int) $group->unread,
            'isRead' => (int) $group->unread === 0,
            'indexedAt' => self::time($group->newest_at),
            'latest' => $latest->where('group_key', $group->group_key)->map(fn ($row) => [
                'uri' => $row->source_uri,
                'actor' => $profiles[$row->actor_did] ?? ['did' => $row->actor_did],
                'view' => $views[$row->source_uri] ?? null,
                'indexedAt' => self::time($row->indexed_at),
            ])->values()->all(),
        ])->values()->all();

        return [
            'notifications' => $items,
            'cursor' => $more ? self::cursor($groups->last()->newest_at, $groups->last()->newest) : null,
            'seenAt' => $seen === null ? null : self::time($seen),
        ];
    }

    /**
     * A page of notifications one at a time, newest first, each with its
     * group key.
     *
     * @return array{notifications: array<int, array<string, mixed>>, cursor: string|null, seenAt: string|null}
     */
    public function list(string $did, int $limit = 50, ?string $cursor = null): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $seen = $this->seenAt($did);

        $query = $this->visible($did)->select('n.*')->orderByDesc('n.indexed_at')->orderByDesc('n.id')->limit($limit + 1);

        if (($after = self::parseCursor($cursor)) !== null) {
            $query->where(fn (Builder $query) => $query
                ->where('n.indexed_at', '<', $after[0])
                ->orWhere(fn (Builder $query) => $query->where('n.indexed_at', $after[0])->where('n.id', '<', $after[1])));
        }

        $rows = $query->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'notifications' => $this->present($rows, $did, $seen),
            'cursor' => $more ? self::cursor($rows->last()->indexed_at, $rows->last()->id) : null,
            'seenAt' => $seen === null ? null : self::time($seen),
        ];
    }

    /**
     * One notification, as list() presents it, or null when its record no
     * longer shows.
     *
     * @return array<string, mixed>|null
     */
    public function one(int $id): ?array
    {
        $row = $this->connection()->table('atp_notifications')->where('id', $id)->first();

        if ($row === null || ! $this->visible($row->recipient_did)->where('n.id', $id)->exists()) {
            return null;
        }

        return $this->present(new Collection([$row]), $row->recipient_did, $this->seenAt($row->recipient_did))[0];
    }

    /**
     * How many notifications arrived since the recipient last looked.
     */
    public function unreadCount(string $did): int
    {
        $seen = $this->seenAt($did);
        $query = $this->visible($did);

        if ($seen !== null) {
            $query->where('n.indexed_at', '>', $this->since($seen));
        }

        return $query->count();
    }

    /**
     * Mark everything up to this time seen, now by default. Seen never moves
     * back.
     */
    public function markSeen(string $did, ?Carbon $at = null): Carbon
    {
        $at = ($at ?? Carbon::now())->clone()->utc();
        $seen = $this->seenAt($did);

        if ($seen !== null && $seen->greaterThanOrEqualTo($at)) {
            return $seen;
        }

        $this->connection()->table('atp_notification_seen')->upsert(
            [['did' => $did, 'seen_at' => $at->format('Y-m-d H:i:s')]],
            ['did'],
            ['seen_at'],
        );

        return $at;
    }

    public function seenAt(string $did): ?Carbon
    {
        $seen = $this->connection()->table('atp_notification_seen')->where('did', $did)->value('seen_at');

        return $seen === null ? null : Carbon::parse($seen, 'UTC');
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function present(Collection $rows, string $did, ?Carbon $seen): array
    {
        $views = $this->views([...$rows->pluck('subject')->all(), ...$rows->pluck('source_uri')->all()], $did);
        $profiles = app(Profiles::class)->basic($rows->pluck('actor_did')->all());
        $since = $this->since($seen);

        return $rows->map(fn ($row) => [
            'groupKey' => $row->group_key,
            'reason' => $row->reason,
            'subject' => $row->subject,
            'subjectView' => $views[$row->subject] ?? null,
            'uri' => $row->source_uri,
            'actor' => $profiles[$row->actor_did] ?? ['did' => $row->actor_did],
            'view' => $views[$row->source_uri] ?? null,
            'isRead' => Carbon::parse($row->indexed_at, 'UTC')->format('Y-m-d H:i:s') <= $since,
            'indexedAt' => self::time($row->indexed_at),
        ])->values()->all();
    }

    /**
     * The newest few notifications of each group.
     *
     * @param  array<int, string>  $keys
     * @return Collection<int, object>
     */
    protected function latest(string $did, array $keys): Collection
    {
        if ($keys === []) {
            return new Collection();
        }

        $ranked = $this->visible($did)
            ->whereIn('n.group_key', $keys)
            ->select('n.id', 'n.group_key', 'n.actor_did', 'n.source_uri', 'n.indexed_at')
            ->selectRaw('row_number() over (partition by n.group_key order by n.indexed_at desc, n.id desc) as nth');

        return $this->connection()->query()
            ->fromSub($ranked, 'ranked')
            ->where('nth', '<=', max(1, (int) config('atp-appview.notifications.latest', 3)))
            ->orderByDesc('indexed_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Views of these uris, by uri, for collections that have one, as the
     * recipient sees them. Records that no longer show have none.
     *
     * @param  array<int, string>  $uris
     * @return array<string, array<string, mixed>>
     */
    protected function views(array $uris, string $did): array
    {
        $views = self::viewsByCollection();
        $wanted = [];

        foreach (array_unique($uris) as $uri) {
            if (preg_match('#^at://[^/]+/([^/]+)/#', $uri, $match) && isset($views[$match[1]])) {
                $wanted[$match[1]][] = $uri;
            }
        }

        $hydrated = [];

        foreach ($wanted as $collection => $list) {
            $records = Record::query()->whereIn('uri', $list)->get();

            foreach (app(Hydrator::class)->hydrate($views[$collection], $records, $did) as $i => $view) {
                $hydrated[$records[$i]->uri] = $view;
            }
        }

        return $hydrated;
    }

    /**
     * The first view of each collection, from atp-appview.xrpc.views and
     * atp-appview.views.
     *
     * @return array<string, View>
     */
    protected static function viewsByCollection(): array
    {
        $views = [];

        foreach (RecordValues::views() as $class) {
            $view = app($class);
            $views[$view->collection()] ??= $view;
        }

        return $views;
    }

    /**
     * A recipient's notifications whose record still shows.
     */
    protected function visible(string $did): Builder
    {
        return $this->connection()->table('atp_notifications', 'n')
            ->join('atp_records as r', 'r.uri', '=', 'n.source_uri')
            ->where('n.recipient_did', $did)
            ->whereNull('r.deleted_at')
            ->whereNull('r.hidden_at')
            ->whereNull('r.moderated_at')
            ->whereNull('r.limited_at');
    }

    /**
     * Where a page ends, newest first: a notification's time and its id,
     * which breaks ties between notifications of the same second.
     */
    protected static function cursor(mixed $indexedAt, mixed $id): string
    {
        return Carbon::parse($indexedAt, 'UTC')->getTimestamp().'-'.(int) $id;
    }

    /**
     * @return array{0: string, 1: int}|null The time as stored, and the id
     */
    protected static function parseCursor(?string $cursor): ?array
    {
        if ($cursor === null || ! preg_match('/^(\d+)-(\d+)$/', $cursor, $match)) {
            return null;
        }

        return [Carbon::createFromTimestamp((int) $match[1], 'UTC')->format('Y-m-d H:i:s'), (int) $match[2]];
    }

    protected function since(?Carbon $seen): string
    {
        return $seen?->format('Y-m-d H:i:s') ?? '1970-01-01 00:00:00';
    }

    protected static function time(mixed $value): string
    {
        return Carbon::parse($value, 'UTC')->toIso8601ZuluString();
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
