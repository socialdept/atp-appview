<?php

namespace SocialDept\AtpAppview\Blocklist;

use Closure;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use SocialDept\AtpAppview\Models\Identity;
use Throwable;

/**
 * Each repo's PDS, from its DID document, kept on atp_identities.
 *
 * Tap events carry only the DID, so host rules need the PDS looked up. A
 * DID document is one GET, from the PLC directory for did:plc or the host's
 * .well-known for did:web, so a batch's unknown DIDs resolve concurrently.
 * A failed lookup is stored as unknown and retried once the TTL passes: an
 * unreachable DID document never blocks a repo.
 */
class PdsLookup
{
    public function __construct(
        protected string $plcDirectory = 'https://plc.directory',
        protected int $ttl = 86400,
        protected int $concurrency = 20,
        protected int $timeout = 5,
    ) {
    }

    /**
     * The PDS of each DID, looking up any not resolved within the TTL.
     *
     * @param  array<int, string>  $dids
     * @return array<string, string|null>
     */
    public function forDids(array $dids): array
    {
        $dids = array_values(array_unique($dids));

        if ($dids === []) {
            return [];
        }

        $known = [];
        $stale = [];
        $fresh = Carbon::now()->subSeconds($this->ttl);

        $rows = Identity::query()->whereIn('did', $dids)->get(['did', 'pds', 'pds_resolved_at'])->keyBy('did');

        foreach ($dids as $did) {
            $row = $rows[$did] ?? null;

            if ($row?->pds_resolved_at !== null && $row->pds_resolved_at->greaterThan($fresh)) {
                $known[$did] = $row->pds;
            } else {
                $stale[] = $did;
            }
        }

        return $known + $this->resolve($stale);
    }

    /**
     * Look up every DID now, whatever is stored, and store the results.
     *
     * @param  array<int, string>  $dids
     * @param  (Closure(int): void)|null  $progress  Called with how many were resolved so far
     * @return array<string, string|null>
     */
    public function resolve(array $dids, ?Closure $progress = null): array
    {
        $resolved = [];

        foreach (array_chunk(array_values(array_unique($dids)), $this->concurrency) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $did) => $pool->as($did)->acceptJson()->timeout($this->timeout)->get($this->documentUrl($did)),
                array_values(array_filter($chunk, fn (string $did) => $this->documentUrl($did) !== '')),
            ));

            foreach ($chunk as $did) {
                $resolved[$did] = $this->pdsFrom($responses[$did] ?? null);
            }

            $this->store(array_intersect_key($resolved, array_flip($chunk)));

            if ($progress !== null) {
                $progress(count($resolved));
            }
        }

        return $resolved;
    }

    public function documentUrl(string $did): string
    {
        if (str_starts_with($did, 'did:plc:')) {
            return rtrim($this->plcDirectory, '/').'/'.$did;
        }

        // atproto only allows did:web at a hostname, with a port encoded as %3A.
        if (str_starts_with($did, 'did:web:') && ! str_contains(substr($did, 8), ':')) {
            return 'https://'.str_replace('%3A', ':', substr($did, 8)).'/.well-known/did.json';
        }

        return '';
    }

    protected function pdsFrom(mixed $response): ?string
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        try {
            foreach ((array) $response->json('service', []) as $service) {
                $id = (string) ($service['id'] ?? '');

                if (($id === '#atproto_pds' || str_ends_with($id, '#atproto_pds')) && is_string($service['serviceEndpoint'] ?? null)) {
                    return rtrim($service['serviceEndpoint'], '/');
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $resolved
     */
    protected function store(array $resolved): void
    {
        if ($resolved === []) {
            return;
        }

        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $rows = array_map(fn (string $did, ?string $pds) => [
            'did' => $did,
            'pds' => $pds,
            'pds_resolved_at' => $now,
            'updated_at' => $now,
        ], array_keys($resolved), $resolved);

        // Only the PDS columns change on an existing identity. Its handle and
        // status stay as Tap last reported them.
        Identity::query()->upsert($rows, ['did'], ['pds', 'pds_resolved_at']);
    }
}
