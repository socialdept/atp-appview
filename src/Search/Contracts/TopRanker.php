<?php

namespace SocialDept\AtpAppview\Search\Contracts;

use SocialDept\AtpAppview\Search\SearchQuery;
use SocialDept\AtpAppview\Search\TopCandidate;

/**
 * How Top orders a search's matches. The package picks the candidates, the
 * recent matches within each collection's window, and gathers what is costly
 * to look up: which sources matched and how many records point at each. The
 * ranker turns those, and anything it reads itself, into a score.
 *
 * Listed as atp-appview.search.ranker.
 */
interface TopRanker
{
    /**
     * @param  array<int, TopCandidate>  $candidates
     * @return array<string, float> URI => score, highest first in Top. A candidate left out scores 0.
     */
    public function scores(array $candidates, SearchQuery $query): array;
}
