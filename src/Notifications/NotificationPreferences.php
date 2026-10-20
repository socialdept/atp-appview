<?php

namespace SocialDept\AtpAppview\Notifications;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\ValueHash;

/**
 * What each account wants to hear about, per reason: whether it shows in the
 * app, whether it is pushed, and whether from everyone or only the accounts
 * they follow. A reason nobody chose for takes the defaults: shown, pushed,
 * from everyone.
 *
 * Choices apply as notifications are read, so changing one changes what the
 * list and unread count already hold, both ways. Pushing is up to the app:
 * a NotificationCreated listener asks wantsPush().
 *
 * "Only the accounts they follow" reads follows from atp-appview.graph.
 * Without them every notification counts as from everyone.
 */
class NotificationPreferences
{
    public const EVERYONE = 'everyone';

    public const FOLLOWS = 'follows';

    /** @var array<string, array<string, array{inApp: bool, push: bool, from: string}>> */
    protected array $stored = [];

    /**
     * The account's choices for these reasons, defaults filled in.
     *
     * @param  array<int, string>  $reasons
     * @return array<string, array{inApp: bool, push: bool, from: string}>
     */
    public function for(string $did, array $reasons): array
    {
        $stored = $this->stored($did);
        $choices = [];

        foreach ($reasons as $reason) {
            $choices[$reason] = $stored[$reason] ?? self::defaults();
        }

        return $choices;
    }

    /**
     * Change some of an account's choices for a reason, keeping the rest.
     *
     * @return array{inApp: bool, push: bool, from: string}
     */
    public function set(string $did, string $reason, ?bool $inApp = null, ?bool $push = null, ?string $from = null): array
    {
        if ($from !== null && ! in_array($from, [self::EVERYONE, self::FOLLOWS], true)) {
            throw new \InvalidArgumentException("From is 'everyone' or 'follows', not '{$from}'.");
        }

        $choice = $this->for($did, [$reason])[$reason];
        $choice = [
            'inApp' => $inApp ?? $choice['inApp'],
            'push' => $push ?? $choice['push'],
            'from' => $from ?? $choice['from'],
        ];

        $this->connection()->table('atp_notification_preferences')->upsert(
            [['did' => $did, 'reason' => $reason, 'in_app' => $choice['inApp'], 'push' => $choice['push'], 'from' => $choice['from']]],
            ['did', 'reason'],
            ['in_app', 'push', 'from'],
        );

        unset($this->stored[$did]);

        return $choice;
    }

    /**
     * Whether a notification for this reason from this actor should be
     * pushed, for a NotificationCreated listener.
     */
    public function wantsPush(string $did, string $reason, string $actor): bool
    {
        $choice = $this->for($did, [$reason])[$reason];

        return $choice['push'] && ($choice['from'] === self::EVERYONE || $this->follows($did, $actor));
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
     * @return array{inApp: bool, push: bool, from: string}
     */
    public static function defaults(): array
    {
        return ['inApp' => true, 'push' => true, 'from' => self::EVERYONE];
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
     * @return array<string, array{inApp: bool, push: bool, from: string}>
     */
    protected function stored(string $did): array
    {
        return $this->stored[$did] ??= $this->connection()->table('atp_notification_preferences')
            ->where('did', $did)
            ->get()
            ->mapWithKeys(fn ($row) => [$row->reason => ['inApp' => (bool) $row->in_app, 'push' => (bool) $row->push, 'from' => (string) $row->from]])
            ->all();
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
