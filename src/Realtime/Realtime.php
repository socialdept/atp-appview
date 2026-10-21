<?php

namespace SocialDept\AtpAppview\Realtime;

use Illuminate\Support\Facades\Cache;
use SocialDept\AtpAppview\Features\Moderation as ModerationFeature;
use SocialDept\AtpAppview\Features\Realtime as RealtimeFeature;
use SocialDept\AtpAppview\Index\Events\RecordIndexed;
use SocialDept\AtpAppview\Index\Events\RecordRemoved;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Notifications\Events\NotificationCreated;

/**
 * Pings for what changed: new records in a collection, records pointing at
 * a record, and an account's notifications. Pings on a channel within the
 * window become one, with a count.
 *
 * Public channels only hear about records a signed-out viewer could see, so
 * nothing hidden, moderated, awaiting review or by an account hidden from
 * guests is ever named on one.
 *
 * Channel names hash URIs and DIDs, whose colons and slashes broadcast
 * channel names cannot hold. The browser hashes them the same way.
 */
class Realtime
{
    public const COLLECTION = 'collection';

    public const SUBJECT = 'subject';

    public const ACCOUNT = 'account';

    public static function collectionChannel(string $nsid): string
    {
        return "appview.collection.{$nsid}";
    }

    public static function subjectChannel(string $uri): string
    {
        return 'appview.subject.'.self::hash($uri);
    }

    /**
     * Private, so with Echo's private- prefix.
     */
    public static function accountChannel(string $did): string
    {
        return 'private-appview.account.'.self::hash($did);
    }

    public static function hash(string $value): string
    {
        return substr(hash('sha256', $value), 0, 32);
    }

    public function indexed(RecordIndexed $event): void
    {
        if (! $this->publiclyVisible($event->uri, $event->did)) {
            return;
        }

        $action = $event->action->value;

        if (in_array($event->collection, self::collections(), true)) {
            $this->ping(self::collectionChannel($event->collection), self::COLLECTION, $event->collection, $event->uri, $action);
        }

        foreach ($this->subjectsOf($event->collection, $event->record) as $subject) {
            $this->ping(self::subjectChannel($subject), self::SUBJECT, $event->collection, $event->uri, $action);
        }
    }

    public function removed(RecordRemoved $event): void
    {
        if ($this->hiddenFromGuests($event->did)) {
            return;
        }

        if (in_array($event->collection, self::collections(), true)) {
            $this->ping(self::collectionChannel($event->collection), self::COLLECTION, $event->collection, $event->uri, 'delete');
        }

        foreach ($this->subjectsOf($event->collection, $event->record) as $subject) {
            $this->ping(self::subjectChannel($subject), self::SUBJECT, $event->collection, $event->uri, 'delete');
        }
    }

    public function notified(NotificationCreated $event): void
    {
        if ((bool) config('atp-appview.realtime.notifications', true)) {
            $this->ping(self::accountChannel($event->recipient), self::ACCOUNT, null, null, 'notification');
        }
    }

    /**
     * Gather a ping for its channel's window, or send it now with no window.
     */
    public function ping(string $channel, string $kind, ?string $collection, ?string $uri, ?string $action): void
    {
        $data = ['kind' => $kind, 'collection' => $collection, 'uri' => $uri, 'action' => $action];
        $window = (int) config('atp-appview.realtime.window', 1);

        if ($window <= 0) {
            broadcast(new Ping($channel, [...$data, 'count' => 1]));

            return;
        }

        $key = 'atp-appview:realtime:'.self::hash($channel);
        Cache::put("{$key}:latest", $data, $window * 10);
        Cache::add("{$key}:count", 0, $window * 10);
        Cache::increment("{$key}:count");

        // The first ping in a window schedules the one message for it.
        if (Cache::add("{$key}:pending", true, $window * 10)) {
            FlushPings::dispatch($channel)->delay($window);
        }
    }

    /**
     * Send what a channel gathered, and open its next window.
     */
    public function flush(string $channel): void
    {
        $key = 'atp-appview:realtime:'.self::hash($channel);
        $data = Cache::pull("{$key}:latest");
        $count = (int) Cache::pull("{$key}:count", 0);
        Cache::forget("{$key}:pending");

        if (is_array($data) && $count > 0) {
            broadcast(new Ping($channel, [...$data, 'count' => $count]));
        }
    }

    /**
     * The auth a Pusher-protocol server (Reverb, Pusher) wants for joining a
     * private channel, or null when the viewer may not join it. An account
     * joins only its own.
     *
     * @return array{auth: string}|null
     */
    public function authorize(string $viewer, string $socketId, string $channel): ?array
    {
        if ($channel !== self::accountChannel($viewer) || preg_match('/^\d+\.\d+$/', $socketId) !== 1) {
            return null;
        }

        $connection = (array) config('broadcasting.connections.'.config('broadcasting.default'), []);
        $key = (string) ($connection['key'] ?? '');
        $secret = (string) ($connection['secret'] ?? '');

        if ($key === '' || $secret === '') {
            return null;
        }

        return ['auth' => $key.':'.hash_hmac('sha256', "{$socketId}:{$channel}", $secret)];
    }

    /**
     * @return array<int, string>
     */
    public static function collections(): array
    {
        return array_values(array_filter((array) config('atp-appview.realtime.collections', []), 'is_string'));
    }

    /**
     * The AT-URIs a record points at through the references realtime names.
     *
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    protected function subjectsOf(string $collection, array $record): array
    {
        $subjects = [];

        foreach ((array) (config('atp-appview.realtime.subjects', [])[$collection] ?? []) as $path) {
            $uri = data_get($record, (string) $path);

            if (is_string($uri) && str_starts_with($uri, 'at://')) {
                $subjects[] = $uri;
            }
        }

        return array_values(array_unique($subjects));
    }

    /**
     * Whether a signed-out viewer could see the record: indexed, not hidden,
     * moderated or awaiting review, and not by an account hidden from guests.
     */
    protected function publiclyVisible(string $uri, string $did): bool
    {
        return Record::query()->where('uri', $uri)->discoverable()->exists() && ! $this->hiddenFromGuests($did);
    }

    protected function hiddenFromGuests(string $did): bool
    {
        return ModerationFeature::on() && app(Labels::class)->account($did)->noUnauthenticated;
    }

    public static function on(): bool
    {
        return RealtimeFeature::on();
    }
}
