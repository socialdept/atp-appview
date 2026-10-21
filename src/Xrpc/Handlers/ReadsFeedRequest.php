<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * The parameters every feed endpoint reads: which feed, the viewer when
 * signed in, how many, from where, and the viewer's languages.
 */
trait ReadsFeedRequest
{
    protected function feedUri(XrpcRequest $request, string $from = 'param'): string
    {
        $feed = $from === 'input' ? ($request->input()['feed'] ?? null) : $request->param('feed');

        if (! is_string($feed) || ! str_starts_with($feed, 'at://')) {
            throw XrpcException::invalidRequest('feed must be the AT-URI of a feed generator record.');
        }

        return $feed;
    }

    protected function viewerDid(XrpcRequest $request): ?string
    {
        return $request->viewer()?->did;
    }

    protected function limit(XrpcRequest $request): int
    {
        return (int) $request->param('limit', 50);
    }

    protected function cursor(XrpcRequest $request): ?string
    {
        $cursor = $request->param('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * From Accept-Language, most wanted first, as BCP 47 tags.
     *
     * @return array<int, string>
     */
    protected function languages(XrpcRequest $request): array
    {
        return array_values(array_unique(array_map(fn (string $tag) => str_replace('_', '-', $tag), $request->http->getLanguages())));
    }

    protected function unknownFeed(): XrpcException
    {
        return new XrpcException('UnknownFeed', 'This service does not run that feed.', 400);
    }
}
