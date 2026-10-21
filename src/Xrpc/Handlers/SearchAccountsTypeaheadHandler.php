<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Search\Search;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Accounts to complete as a query is typed, served under
 * atp-appview.search.xrpc.typeahead, in the shape of
 * app.bsky.actor.searchActorsTypeahead: q (or the older term) and limit.
 * Answers {actors}, as basic profiles.
 */
class SearchAccountsTypeaheadHandler
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
        return $this->search->accounts(
            (string) ($request->param('q') ?? $request->param('term') ?? ''),
            $request->viewer()?->did,
            (int) $request->param('limit', 10),
            typeahead: true,
        );
    }
}
