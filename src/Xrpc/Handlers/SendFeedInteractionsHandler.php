<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Feeds\FeedInteractions;
use SocialDept\AtpAppview\Feeds\Feeds;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Keeps what a viewer did with a feed's items, served under
 * atp-appview.feeds.lexicons.interactions, in the shape of
 * app.bsky.feed.sendInteractions. Input: {feed, interactions: [{item,
 * event, feedContext?, reqId?}]}. Answers {}.
 */
class SendFeedInteractionsHandler
{
    use ReadsFeedRequest;

    public const MAX = 100;

    public function __construct(
        protected Feeds $feeds,
        protected FeedInteractions $interactions,
    ) {
    }

    /**
     * @return array<string, never>
     */
    public function __invoke(XrpcRequest $request): array
    {
        $viewer = $this->viewerDid($request) ?? throw new XrpcException('AuthenticationRequired', 'Interactions belong to a signed-in account.', 401);
        $feed = $this->feedUri($request, 'input');

        if ($this->feeds->find($feed) === null) {
            throw $this->unknownFeed();
        }

        $given = $request->input()['interactions'] ?? null;

        if (! is_array($given) || count($given) > self::MAX) {
            throw XrpcException::invalidRequest('interactions must be a list of at most '.self::MAX.'.');
        }

        $kept = [];

        foreach ($given as $interaction) {
            $item = $interaction['item'] ?? null;
            $event = $interaction['event'] ?? null;

            // Items and events are optional in the lexicon, so a partial one is skipped.
            if (! is_string($item) || ! str_starts_with($item, 'at://') || ! is_string($event) || $event === '') {
                continue;
            }

            $kept[] = [
                'item' => $item,
                'event' => $event,
                'feedContext' => is_string($interaction['feedContext'] ?? null) ? $interaction['feedContext'] : null,
                'reqId' => is_string($interaction['reqId'] ?? null) ? $interaction['reqId'] : null,
            ];
        }

        $this->interactions->record($viewer, $feed, $kept);

        return [];
    }
}
