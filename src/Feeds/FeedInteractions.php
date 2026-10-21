<?php

namespace SocialDept\AtpAppview\Feeds;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Features\Feeds as FeedsFeature;

/**
 * What viewers did with feed items, as their clients report it: asked for
 * more or less, opened, saw. Kept per viewer in atp_feed_interactions for
 * feeds to read, such as pushing down what a viewer asked for less of.
 * Events are stored by their token's name, without the defs lexicon.
 */
class FeedInteractions
{
    public const REQUEST_MORE = 'requestMore';

    public const REQUEST_LESS = 'requestLess';

    /**
     * Keep interactions a viewer sent for a feed.
     *
     * @param  array<int, array{item: string, event: string, feedContext?: string|null, reqId?: string|null}>  $interactions
     * @return int How many were kept
     */
    public function record(string $viewer, string $feed, array $interactions): int
    {
        FeedsFeature::ensureOn();

        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($interactions as $interaction) {
            $rows[] = [
                'viewer_did' => $viewer,
                'feed' => $feed,
                'item' => mb_substr($interaction['item'], 0, 512),
                'event' => mb_substr(self::token($interaction['event']), 0, 64),
                'feed_context' => isset($interaction['feedContext']) ? mb_substr((string) $interaction['feedContext'], 0, 2000) : null,
                'req_id' => isset($interaction['reqId']) ? mb_substr((string) $interaction['reqId'], 0, 100) : null,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->connection()->table('atp_feed_interactions')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * The items a viewer sent an event for in a feed, most recent first.
     *
     * @return array<int, string>
     */
    public function items(string $viewer, string $feed, string $event, ?DateTimeInterface $since = null): array
    {
        return $this->query($viewer, $feed, $event, $since)->orderByDesc('id')->pluck('item')->unique()->values()->all();
    }

    /**
     * The authors of those items, with how many times each, most first.
     *
     * @return array<string, int> DID => count
     */
    public function authors(string $viewer, string $feed, string $event, ?DateTimeInterface $since = null): array
    {
        $counts = [];

        foreach ($this->query($viewer, $feed, $event, $since)->pluck('item') as $item) {
            if (preg_match('#^at://([^/]+)/#', $item, $match)) {
                $counts[$match[1]] = ($counts[$match[1]] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * The feed contexts of those items, with how many times each, most
     * first: what the feed said about an item when it served it.
     *
     * @return array<string, int>
     */
    public function contexts(string $viewer, string $feed, string $event, ?DateTimeInterface $since = null): array
    {
        $counts = $this->query($viewer, $feed, $event, $since)->whereNotNull('feed_context')->pluck('feed_context')->countBy()->all();
        arsort($counts);

        return $counts;
    }

    /**
     * Forget interactions older than atp-appview.feeds.interactions_days.
     *
     * @return int How many were forgotten
     */
    public function prune(?int $days = null): int
    {
        $days ??= (int) config('atp-appview.feeds.interactions_days', 90);

        return $this->connection()->table('atp_feed_interactions')
            ->where('created_at', '<', Carbon::now('UTC')->subDays($days)->format('Y-m-d H:i:s'))
            ->delete();
    }

    /**
     * An event's token name: #requestLess and app.bsky.feed.defs#requestLess
     * are both requestLess.
     */
    public static function token(string $event): string
    {
        return str_contains($event, '#') ? substr($event, strrpos($event, '#') + 1) : $event;
    }

    protected function query(string $viewer, string $feed, string $event, ?DateTimeInterface $since): \Illuminate\Database\Query\Builder
    {
        FeedsFeature::ensureOn();

        return $this->connection()->table('atp_feed_interactions')
            ->where('viewer_did', $viewer)
            ->where('feed', $feed)
            ->where('event', self::token($event))
            ->when($since !== null, fn ($query) => $query->where('created_at', '>=', Carbon::instance($since)->utc()->format('Y-m-d H:i:s')));
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
