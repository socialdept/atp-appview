<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use SocialDept\AtpAppview\Search\Search;
use SocialDept\AtpAppview\Search\SearchException;
use SocialDept\AtpAppview\Search\SearchQuery;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Records matching a query, served under atp-appview.search.xrpc.records, in
 * the shape of app.bsky.feed.searchPosts: q, sort (top or latest), since,
 * until, author, tag, limit and cursor, plus an optional collection.
 * Answers {records, cursor, hitsTotal}. A filter the index cannot answer is
 * refused with BadQueryString rather than ignored.
 */
class SearchRecordsHandler
{
    /**
     * Bluesky's filters this index does not answer.
     */
    public const UNSUPPORTED = ['mentions', 'lang', 'domain', 'url'];

    public function __construct(
        protected Search $search,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(XrpcRequest $request): array
    {
        foreach (self::UNSUPPORTED as $filter) {
            if ($request->param($filter) !== null) {
                throw new XrpcException('BadQueryString', "This search does not filter by {$filter}.", 400);
            }
        }

        $sort = (string) $request->param('sort', Search::LATEST);
        $string = fn (string $name) => is_string($value = $request->param($name)) && $value !== '' ? $value : null;

        try {
            return $this->search->records(new SearchQuery(
                q: (string) $request->param('q', ''),
                viewer: $request->viewer()?->did,
                sort: $sort === Search::TOP ? Search::TOP : Search::LATEST,
                collection: $string('collection'),
                author: $string('author'),
                since: $string('since'),
                until: $string('until'),
                tags: array_values(array_filter((array) $request->param('tag', []), 'is_string')),
                limit: (int) $request->param('limit', 25),
                cursor: $string('cursor'),
            ));
        } catch (SearchException $e) {
            throw new XrpcException('BadQueryString', $e->getMessage(), 400);
        }
    }
}
