<?php

namespace SocialDept\AtpAppview\Feeds;

/**
 * What a feed is asked for: by whom, from where and how many.
 */
final class FeedRequest
{
    /**
     * @param  array<int, string>  $languages  The viewer's languages, most wanted first
     */
    public function __construct(
        public readonly string $feed,
        public readonly ?string $viewer,
        public readonly ?string $cursor,
        public readonly int $limit,
        public readonly array $languages = [],
    ) {
    }
}
