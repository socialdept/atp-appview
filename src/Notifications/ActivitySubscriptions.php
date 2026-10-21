<?php

namespace SocialDept\AtpAppview\Notifications;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SocialDept\AtpAppview\Features\Notifications as NotificationsFeature;

/**
 * Accounts asking to hear when another account writes: Bluesky's "Keep me
 * posted". The kinds of activity are the app's, named in
 * atp-appview.notifications.activity, and ActivityNotifier sends them.
 * Kept privately in atp_activity_subscriptions, never written to a repo.
 */
class ActivitySubscriptions
{
    /**
     * The kinds of activity an account can subscribe to, as configured.
     *
     * @return array<string, array{collection: string, reason: string, with?: string, without?: string}>
     */
    public static function kinds(): array
    {
        return (array) config('atp-appview.notifications.activity', []);
    }

    /**
     * Subscribe $did to some of $subject's activity, replacing what it had.
     * No kinds unsubscribes.
     *
     * @param  array<int, string>  $kinds
     *
     * @throws InvalidArgumentException for a kind not configured, or the account itself
     */
    public function set(string $did, string $subject, array $kinds): void
    {
        NotificationsFeature::ensureOn();

        if ($did === $subject) {
            throw new InvalidArgumentException('An account cannot subscribe to itself.');
        }

        $kinds = array_values(array_unique($kinds));

        if (($unknown = array_diff($kinds, array_keys(self::kinds()))) !== []) {
            throw new InvalidArgumentException('Unknown activity: '.implode(', ', $unknown).'.');
        }

        $table = $this->connection()->table('atp_activity_subscriptions');

        if ($kinds === []) {
            $table->where('subscriber_did', $did)->where('subject_did', $subject)->delete();

            return;
        }

        $table->upsert([[
            'subscriber_did' => $did,
            'subject_did' => $subject,
            'kinds' => json_encode($kinds),
            'created_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ]], ['subscriber_did', 'subject_did'], ['kinds']);
    }

    /**
     * The kinds of $subject's activity $did hears about.
     *
     * @return array<int, string>
     */
    public function for(string $did, string $subject): array
    {
        if (! NotificationsFeature::on()) {
            return [];
        }

        $kinds = $this->connection()->table('atp_activity_subscriptions')
            ->where('subscriber_did', $did)->where('subject_did', $subject)->value('kinds');

        return $kinds === null ? [] : $this->known((string) $kinds);
    }

    /**
     * Everyone $did is subscribed to, with the kinds, newest first.
     *
     * @return array<string, array<int, string>> Subject DID => kinds
     */
    public function subscriptions(string $did): array
    {
        if (! NotificationsFeature::on()) {
            return [];
        }

        return $this->connection()->table('atp_activity_subscriptions')
            ->where('subscriber_did', $did)
            ->orderByDesc('created_at')
            ->get(['subject_did', 'kinds'])
            ->mapWithKeys(fn ($row) => [$row->subject_did => $this->known((string) $row->kinds)])
            ->all();
    }

    /**
     * Who hears about each of these accounts' activity of a kind.
     *
     * @param  array<int, string>  $subjects
     * @return array<string, array<int, string>> Subject DID => subscriber DIDs
     */
    public function subscribers(array $subjects, string $kind): array
    {
        if ($subjects === [] || ! NotificationsFeature::on()) {
            return [];
        }

        $subscribers = [];

        foreach (array_chunk(array_values(array_unique($subjects)), 500) as $chunk) {
            $this->connection()->table('atp_activity_subscriptions')
                ->whereIn('subject_did', $chunk)
                ->get(['subscriber_did', 'subject_did', 'kinds'])
                ->each(function ($row) use ($kind, &$subscribers) {
                    if (in_array($kind, $this->known((string) $row->kinds), true)) {
                        $subscribers[$row->subject_did][] = $row->subscriber_did;
                    }
                });
        }

        return $subscribers;
    }

    /**
     * Stored kinds still configured, so removing one from config stops it.
     *
     * @return array<int, string>
     */
    protected function known(string $json): array
    {
        return array_values(array_intersect((array) json_decode($json, true), array_keys(self::kinds())));
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
