<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Search;

use SocialDept\AtpAppview\Search\Contracts\TopRanker;
use SocialDept\AtpAppview\Search\SearchQuery;

/**
 * Keeps the candidates it was given, to show what Top hands a ranker.
 */
class RecordingRanker implements TopRanker
{
    /**
     * @param  array<int, \SocialDept\AtpAppview\Search\TopCandidate>  $seen
     */
    public function __construct(public array &$seen)
    {
    }

    public function scores(array $candidates, SearchQuery $query): array
    {
        array_push($this->seen, ...$candidates);

        return [];
    }
}
