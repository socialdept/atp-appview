<?php

namespace SocialDept\AtpAppview\Notifications;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Features\Notifications as NotificationsFeature;
use SocialDept\AtpAppview\Features\Push as PushFeature;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\ValueHash;

/**
 * What each account wants to hear about, per reason: whether it shows in the
 * app, whether from everyone or only the accounts they follow, and whether
 * each of the app's channels carries it. A reason nobody chose for takes the
 * defaults: shown, from everyone, each channel as configured.
 *
 * Shown and from apply as notifications are read, so changing one changes
 * what the list and unread count already hold, both ways. Channels, such as
 * push or email, are the app's, named in atp-appview.notifications.channels.
 * The package only keeps the choice: a NotificationCreated listener asks
 * wants() before sending.
 *
 * "Only the accounts they follow" reads follows from atp-appview.graph.
 * Without them every notification counts as from everyone.
 */
class NotificationPreferences
{
    public const EVERYONE = 'everyone';

    public const FOLLOWS = 'follows';

    /** @var array<string, array<string, array{inApp: bool, from: string, channels: array<string, bool>}>> */
    protected array $stored = [];

    /**
     * The account's choices for these reasons, defaults filled in.
     *
     * @param  array<int, string>  $reasons
     * @return array<string, array{inApp: bool, from: string, channels: array<string, bool>}>
     */
    public function for(string $did, array $reasons): array
    {
        NotificationsFeature::ensureOn();

        $stored = $this->stored($did);
        $defaults = self::defaults();
        $choices = [];

        foreach ($reasons as $reason) {
            $choice = $stored[$reason] ?? $defaults;
            // Channels keep only those configured, with defaults for the rest.
            $choice['channels'] = array_replace($defaults['channels'], array_intersect_key($choice['channels'], $defaults['channels']));
            $choices[$reason] = $choice;
        }

        return $choices;
    }

    /**
     * The app's channels, each with whether it carries a reason nobody chose
     * for.
     *
     * @return array<string, bool>
     */
    public static function channels(): array
    {
        $channels = [];

        foreach ((array) config('atp-appview.notifications.channels', []) as $name => $default) {
            is_int($name) ? $channels[(string) $default] = true : $channels[(string) $name] = (bool) $default;
        }

        if (Features::enabled(PushFeature::class)) {
            $channels += ['push' => true];
        }

        return $channels;
    }

    /**
     * Change some of an account's choices for a reason, keeping the rest.
     *
     * @param  array<string, bool>  $channels  Only the channels to change
     * @return array{inApp: bool, from: string, channels: array<string, bool>}
     */
    public function set(string $did, string $reason, ?bool $inApp = null, ?string $from = null, array $channels = []): array
    {
        NotificationsFeature::ensureOn();

        if ($from !== null && ! in_array($from, [self::EVERYONE, self::FOLLOWS], true)) {
            throw new \InvalidArgumentException("From is 'everyone' or 'follows', not '{$from}'.");
        }

        if (($unknown = array_diff(array_keys($channels), array_keys(self::channels()))) !== []) {
            throw new \InvalidArgumentException('No such notification channel: '.implode(', ', $unknown).'. Add it to atp-appview.notifications.channels.');
        }

        $choice = $this->for($did, [$reason])[$reason];
        $choice = [
            'inApp' => $inApp ?? $choice['inApp'],
            'from' => $from ?? $choice['from'],
            'channels' => array_replace($choice['channels'], array_map('boolval', $channels)),
        ];

        $this->connection()->table('atp_notification_preferences')->upsert(
            [['did' => $did, 'reason' => $reason, 'in_app' => $choice['inApp'], 'from' => $choice['from'], 'channels' => json_encode($choice['channels'])]],
            ['did', 'reason'],
            ['in_app', 'from', 'channels'],
        );

        unset($this->stored[$did]);

        return $choice;
    }

    /**
     * Whether a channel should carry a notification for this reason from this
     * actor, for a NotificationCreated listener. Only from followed accounts
     * holds here too. False for a channel not configured.
     */
    public function wants(string $did, string $reason, string $actor, string $channel): bool
    {
        NotificationsFeature::ensureOn();

        $choice = $this->for($did, [$reason])[$reason];

        return ($choice['channels'][$channel] ?? false) && ($choice['from'] === self::EVERYONE || $this->follows($did, $actor));
    }

    /**
     * Whether the account follows the actor, from the follow collection the
     * index keeps. True when no follow collection is configured.
     */
    public function follows(string $did, string $actor): bool
    {
        return Graph::relation(Graph::FOLLOWS) === null || app(Graph::class)->follows($did, $actor);
    }

    /**
     * Narrow a query over atp_notifications to what the account wants shown:
     * no reasons turned off in the app, and only followed actors for reasons
     * limited to them.
     */
    public function apply(Builder $query, string $did, string $alias = 'n'): Builder
    {
        $hidden = [];
        $followsOnly = [];

        foreach ($this->stored($did) as $reason => $choice) {
            if (! $choice['inApp']) {
                $hidden[] = $reason;
            } elseif ($choice['from'] === self::FOLLOWS) {
                $followsOnly[] = $reason;
            }
        }

        if ($hidden !== []) {
            $query->whereNotIn("{$alias}.reason", $hidden);
        }

        $follows = $this->followKey();

        if ($followsOnly !== [] && $follows !== null) {
            $query->where(fn (Builder $query) => $query
                ->whereNotIn("{$alias}.reason", $followsOnly)
                ->orWhereExists(fn (Builder $exists) => $exists
                    ->selectRaw('1')
                    ->from(RecordValues::VALUES.' as follow')
                    ->where('follow.did_hash', ValueHash::of($did))
                    ->where('follow.key_id', $follows)
                    ->whereColumn('follow.value_hash', "{$alias}.actor_hash")
                    ->where('follow.hidden', false)));
        }

        return $query;
    }

    /**
     * @return array{inApp: bool, from: string, channels: array<string, bool>}
     */
    public static function defaults(): array
    {
        return ['inApp' => true, 'from' => self::EVERYONE, 'channels' => self::channels()];
    }

    /**
     * The follow field's key, or null when none is configured.
     */
    protected function followKey(): ?int
    {
        $follows = Graph::relation(Graph::FOLLOWS);

        if ($follows === null) {
            return null;
        }

        $values = app(RecordValues::class);
        $field = $values->field($follows['collection'], $follows['path']);

        return $field === null ? null : $values->keyId($field);
    }

    /**
     * @return array<string, array{inApp: bool, from: string, channels: array<string, bool>}>
     */
    protected function stored(string $did): array
    {
        return $this->stored[$did] ??= $this->connection()->table('atp_notification_preferences')
            ->where('did', $did)
            ->get()
            ->mapWithKeys(fn ($row) => [$row->reason => [
                'inApp' => (bool) $row->in_app,
                'from' => (string) $row->from,
                'channels' => array_map('boolval', (array) json_decode((string) ($row->channels ?? '[]'), true)),
            ]])
            ->all();
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
