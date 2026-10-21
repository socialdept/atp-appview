<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Search;

use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Search\Contracts\TopRanker;
use SocialDept\AtpAppview\Search\Rankers\RecentEngagement;
use SocialDept\AtpAppview\Search\SearchQuery;

/**
 * An app's own Top: the default, plus a boost for authors the viewer follows.
 */
class FollowedFirst implements TopRanker
{
    public function scores(array $candidates, SearchQuery $query): array
    {
        $scores = (new RecentEngagement())->scores($candidates, $query);
        $following = $query->viewer === null ? [] : array_flip(app(Graph::class)->following($query->viewer));

        foreach ($candidates as $candidate) {
            if (isset($following[$candidate->did])) {
                $scores[$candidate->uri] *= 20;
            }
        }

        return $scores;
    }
}
