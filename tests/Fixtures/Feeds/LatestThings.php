<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Feeds;

use SocialDept\AtpAppview\Feeds\Contracts\Feed;
use SocialDept\AtpAppview\Feeds\FeedInteractions;
use SocialDept\AtpAppview\Feeds\FeedItem;
use SocialDept\AtpAppview\Feeds\FeedPage;
use SocialDept\AtpAppview\Feeds\FeedRequest;
use SocialDept\AtpAppview\Models\Record;

/**
 * The newest things, leaving out authors the viewer asked for less of.
 * Items outside the index can be pinned on top through $pinned.
 */
class LatestThings implements Feed
{
    /** @var array<int, string> */
    public static array $pinned = [];

    public static ?FeedRequest $last = null;

    public function __construct(
        protected FeedInteractions $interactions,
    ) {
    }

    public function describe(): array
    {
        return ['displayName' => 'Latest things', 'description' => 'The newest things.', 'acceptsInteractions' => true];
    }

    public function skeleton(FeedRequest $request): FeedPage
    {
        self::$last = $request;
        $offset = (int) ($request->cursor ?? 0);
        // Pins lead the first page and count toward its limit.
        $pins = $offset === 0 ? array_slice(self::$pinned, 0, $request->limit) : [];
        $limit = $request->limit - count($pins);
        $less = $request->viewer === null ? [] : array_keys($this->interactions->authors($request->viewer, $request->feed, FeedInteractions::REQUEST_LESS));

        $uris = Record::query()->collection('com.example.thing')
            ->whereNotIn('did', $less)
            ->orderByDesc('sort_at')->orderByDesc('uri')
            ->offset($offset)->limit($limit + 1)
            ->pluck('uri')->all();

        $items = [
            ...array_map(fn (string $uri) => FeedItem::pin($uri), $pins),
            ...array_map(fn (string $uri) => new FeedItem($uri, feedContext: 'latest'), array_slice($uris, 0, $limit)),
        ];

        return new FeedPage($items, count($uris) > $limit ? (string) ($offset + $limit) : null);
    }
}
