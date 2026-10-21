<?php

namespace SocialDept\AtpAppview\Search;

use Carbon\CarbonInterface;

/**
 * One match Top ranks, with what the package already looked up about it.
 */
final class TopCandidate
{
    /**
     * @param  array<int, string>  $matched  The collection's sources the query's words were found in
     * @param  array<string, int>  $counts  Records pointing at it, keyed collection:path as its Top counts them
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $did,
        public readonly string $collection,
        public readonly ?CarbonInterface $sortAt,
        public readonly array $matched = [],
        public readonly array $counts = [],
    ) {
    }

    /**
     * Hours since the record's sort time, never negative.
     */
    public function hours(?CarbonInterface $now = null): float
    {
        $now ??= now('UTC');

        return max(0.0, $now->diffInMinutes($this->sortAt ?? $now, true) / 60);
    }
}
