<?php

namespace SocialDept\AtpAppview\Ingest;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client for Tap's admin HTTP API: which repos it tracks, and how far it got.
 */
class TapAdmin
{
    public function __construct(
        protected string $baseUrl,
        protected ?string $password = null,
        protected int $timeout = 30,
    ) {
    }

    /**
     * Start tracking repos. Tap backfills each one, then follows it live.
     *
     * @param  array<int, string>  $dids
     */
    public function addRepos(array $dids): void
    {
        $this->request()->post('/repos/add', ['dids' => array_values($dids)])->throw();
    }

    /**
     * Stop tracking repos and drop Tap's state for them.
     *
     * @param  array<int, string>  $dids
     */
    public function removeRepos(array $dids): void
    {
        $this->request()->post('/repos/remove', ['dids' => array_values($dids)])->throw();
    }

    /**
     * Tap's sync state for one repo.
     *
     * @return array<string, mixed>
     */
    public function info(string $did): array
    {
        return (array) $this->request()->get('/info/'.$did)->throw()->json();
    }

    /**
     * Backfill a repo Tap already tracks again, keeping what it already sent,
     * so only records it never delivered go out, such as those of a
     * collection added to its filters. Returns the repo's resulting state, or
     * null if Tap does not track the repo.
     *
     * Needs a Tap with POST /resync/:did, such as the socialde.pt/tap fork.
     * Check with supportsResync() first.
     *
     * @throws TapFeatureUnavailableException when the Tap has no resync route
     */
    public function resyncRepo(string $did): ?string
    {
        $response = $this->request()->post('/resync/'.$did);

        if ($response->status() === 404) {
            return $this->isMissingRoute($response)
                ? throw TapFeatureUnavailableException::resync()
                : null;
        }

        return (string) $response->throw()->json('state');
    }

    /**
     * Backfill every repo Tap tracks again, the same way. Each repo's live
     * events wait until its resync runs, so prefer resyncRepo() for the repos
     * that need it. Returns how many repos were queued.
     *
     * @throws TapFeatureUnavailableException when the Tap has no resync route
     */
    public function resyncAll(): int
    {
        $response = $this->request()->post('/resync-all');

        if ($response->status() === 404 && $this->isMissingRoute($response)) {
            throw TapFeatureUnavailableException::resync();
        }

        return (int) $response->throw()->json('count');
    }

    /**
     * Whether this Tap can resync tracked repos. Asks to resync a DID no Tap
     * tracks: a Tap with the route answers that the repo was not found, one
     * without it that the route was not found. Nothing is queued either way.
     */
    public function supportsResync(): bool
    {
        try {
            $this->resyncRepo('did:plc:aaaaaaaaaaaaaaaaaaaaaaaa');

            return true;
        } catch (TapFeatureUnavailableException) {
            return false;
        }
    }

    /**
     * One of Tap's counters: repo-count, record-count, outbox-buffer,
     * resync-buffer or cursors.
     *
     * @return array<string, mixed>
     */
    public function stats(string $name): array
    {
        return (array) $this->request()->get('/stats/'.$name)->throw()->json();
    }

    public function healthy(): bool
    {
        return $this->request()->get('/health')->successful();
    }

    /**
     * Tap answers an unknown route with its router's generic 404, and a
     * repo it does not track with "repo not found".
     */
    protected function isMissingRoute(Response $response): bool
    {
        return $response->json('message') !== 'repo not found';
    }

    protected function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))->acceptJson()->timeout($this->timeout);

        return $this->password !== null && $this->password !== ''
            ? $request->withBasicAuth('admin', $this->password)
            : $request;
    }
}
