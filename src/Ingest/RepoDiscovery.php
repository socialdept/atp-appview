<?php

namespace SocialDept\AtpAppview\Ingest;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Finds repos that hold records in a collection, through
 * com.atproto.sync.listReposByCollection.
 *
 * Bluesky's relays answer it, and so does Light Rail. Tap enumerates its one
 * signal collection the same way. This lets an AppView discover repos for
 * every collection it indexes, then hand them to Tap.
 */
class RepoDiscovery
{
    public const METHOD = 'com.atproto.sync.listReposByCollection';

    public function __construct(
        protected string $source,
        protected int $pageSize = 2000,
        protected int $timeout = 30,
    ) {
    }

    /**
     * Every DID with at least one record in the collection, page by page.
     *
     * @return Generator<int, string>
     */
    public function dids(string $collection): Generator
    {
        $cursor = null;
        $seenCursors = [];

        do {
            $page = $this->page($collection, $cursor);

            foreach ($page['repos'] ?? [] as $repo) {
                if (is_string($repo['did'] ?? null)) {
                    yield $repo['did'];
                }
            }

            $cursor = is_string($page['cursor'] ?? null) && $page['cursor'] !== '' ? $page['cursor'] : null;

            // A source that hands back a cursor it already gave would loop forever.
            if ($cursor !== null && isset($seenCursors[$cursor])) {
                throw new RuntimeException("Discovery source repeated cursor {$cursor} for {$collection}.");
            }

            if ($cursor !== null) {
                $seenCursors[$cursor] = true;
            }
        } while ($cursor !== null && ($page['repos'] ?? []) !== []);
    }

    /**
     * @return array<string, mixed>
     */
    protected function page(string $collection, ?string $cursor): array
    {
        $response = $this->request()->get('/xrpc/'.self::METHOD, array_filter([
            'collection' => $collection,
            'limit' => $this->pageSize,
            'cursor' => $cursor,
        ], fn ($value) => $value !== null));

        return (array) $response->throw()->json();
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->source, '/'))
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(3, 1000, fn (Throwable $e) => $this->worthRetrying($e), throw: false);
    }

    /**
     * Rate limits, server errors and dropped connections are worth waiting
     * out. A bad request is not.
     */
    protected function worthRetrying(Throwable $e): bool
    {
        if (! $e instanceof RequestException) {
            return true;
        }

        return $e->response->status() === 429 || $e->response->serverError();
    }
}
