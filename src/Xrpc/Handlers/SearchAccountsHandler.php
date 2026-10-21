<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Search\Search;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Accounts the index holds matching a query, served under
 * atp-appview.search.xrpc.accounts, in the shape of
 * app.bsky.actor.searchActors: q (or the older term), limit and cursor.
 * Answers {actors, cursor}.
 */
class SearchAccountsHandler
{
    public function __construct(
        protected Search $search,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(XrpcRequest $request): array
    {
        $cursor = $request->param('cursor');

        return $this->search->accounts(
            (string) ($request->param('q') ?? $request->param('term') ?? ''),
            $request->viewer()?->did,
            (int) $request->param('limit', 25),
            is_string($cursor) && $cursor !== '' ? $cursor : null,
        );
    }
}
