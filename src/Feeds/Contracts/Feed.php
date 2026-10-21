<?php

namespace SocialDept\AtpAppview\Feeds\Contracts;

use SocialDept\AtpAppview\Feeds\FeedPage;
use SocialDept\AtpAppview\Feeds\FeedRequest;

/**
 * One custom feed: what its generator record says, and the skeleton of a
 * page. Listed in atp-appview.feeds.feeds under its record's rkey.
 */
interface Feed
{
    /**
     * The generator record's own fields. displayName is required, and
     * description, acceptsInteractions and contentMode are optional. An
     * avatar is a path to a local image, uploaded when published.
     *
     * @return array<string, mixed>
     */
    public function describe(): array;

    public function skeleton(FeedRequest $request): FeedPage;
}
