<?php

namespace SocialDept\AtpAppview\Ingest;

use Illuminate\Http\Client\PendingRequest;
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
        return (array) $this->request()->get('/info/'.rawurlencode($did))->throw()->json();
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

    protected function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))->acceptJson()->timeout($this->timeout);

        return $this->password !== null && $this->password !== ''
            ? $request->withBasicAuth('admin', $this->password)
            : $request;
    }
}
