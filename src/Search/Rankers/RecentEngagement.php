<?php

namespace SocialDept\AtpAppview\Search\Rankers;

use SocialDept\AtpAppview\Search\Contracts\TopRanker;
use SocialDept\AtpAppview\Search\SearchQuery;

/**
 * The default Top: one point, plus one for each source matched and each
 * record pointing at the match, decayed with age, so a fresh match with some
 * engagement beats an old one with a little more.
 */
class RecentEngagement implements TopRanker
{
    public function scores(array $candidates, SearchQuery $query): array
    {
        $now = now('UTC');
        $scores = [];

        foreach ($candidates as $candidate) {
            $signals = 1 + count($candidate->matched) + array_sum($candidate->counts);
            $scores[$candidate->uri] = $signals / (($candidate->hours($now) + 2) ** 1.5);
        }

        return $scores;
    }
}
