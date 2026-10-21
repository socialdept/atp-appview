<?php

namespace SocialDept\AtpAppview\Feeds;

/**
 * A page of a feed's skeleton, and where the next one starts.
 */
final class FeedPage
{
    /**
     * @param  array<int, FeedItem|string>  $items  Items, or bare AT-URIs
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $cursor = null,
    ) {
    }

    /**
     * @return array<int, FeedItem>
     */
    public function items(): array
    {
        return array_map(fn (FeedItem|string $item) => is_string($item) ? new FeedItem($item) : $item, array_values($this->items));
    }
}
