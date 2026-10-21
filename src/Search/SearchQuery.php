<?php

namespace SocialDept\AtpAppview\Search;

/**
 * A record search: the words, the sort, and Bluesky's filters the index can
 * answer.
 */
final class SearchQuery
{
    /**
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public readonly string $q,
        public readonly ?string $viewer = null,
        public readonly string $sort = Search::LATEST,
        public readonly ?string $collection = null,
        public readonly ?string $author = null,
        public readonly ?string $since = null,
        public readonly ?string $until = null,
        public readonly array $tags = [],
        public readonly int $limit = 25,
        public readonly ?string $cursor = null,
    ) {
    }
}
