<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Feeds\Feeds;
use SocialDept\AtpAppview\Xrpc\ServiceIdentity;

/**
 * The feeds this service runs, served under atp-appview.feeds.lexicons.
 * describe, in the shape of app.bsky.feed.describeFeedGenerator.
 */
class DescribeFeedGeneratorHandler
{
    public function __construct(
        protected ServiceIdentity $identity,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $links = array_filter((array) config('atp-appview.feeds.links', []), fn ($link) => is_string($link) && $link !== '');

        return array_filter([
            'did' => $this->identity->did(),
            'feeds' => array_values(array_map(fn (string $rkey) => ['uri' => Feeds::uri($rkey)], array_filter(array_keys(Feeds::configured()), fn (string $rkey) => Feeds::uri($rkey) !== null))),
            'links' => $links === [] ? null : $links,
        ], fn ($value) => $value !== null);
    }
}
