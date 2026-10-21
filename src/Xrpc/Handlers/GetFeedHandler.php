<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Feeds\Feeds;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * A page of a feed with each item as its view, for the app's own clients,
 * served under atp-appview.feeds.lexicons.feed. The viewer is optional.
 */
class GetFeedHandler
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
        return $this->feeds->hydrated(
            $this->feedUri($request),
            $this->viewerDid($request),
            $this->cursor($request),
            $this->limit($request),
            $this->languages($request),
        ) ?? throw $this->unknownFeed();
    }
}
