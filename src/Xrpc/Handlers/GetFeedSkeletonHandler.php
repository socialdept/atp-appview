<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Feeds\Feeds;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * A page of a feed's skeleton, served under atp-appview.feeds.lexicons.
 * skeleton, in the shape of app.bsky.feed.getFeedSkeleton. Auth is optional:
 * a service auth token the viewer's PDS signed says who is reading.
 */
class GetFeedSkeletonHandler
{
    use ReadsFeedRequest;

    public function __construct(
        protected Feeds $feeds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(XrpcRequest $request): array
    {
        return $this->feeds->skeleton(
            $this->feedUri($request),
            $this->viewerDid($request),
            $this->cursor($request),
            $this->limit($request),
            $this->languages($request),
        ) ?? throw $this->unknownFeed();
    }
}
