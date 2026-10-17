<?php

namespace SocialDept\AtpAppview\Auth;

use Illuminate\Contracts\Cache\Repository as Cache;
use RuntimeException;
use SocialDept\AtpClient\Auth\AuthorizationServerDiscovery;
use SocialDept\AtpSupport\Facades\Resolver;
use Throwable;

/**
 * An account as this server resolves it, never as a browser describes it:
 * its PDS, the authorization server that PDS uses, and its handle when the
 * handle points back at the DID.
 */
class AccountResolver
{
    public function __construct(
        protected AuthorizationServerDiscovery $discovery,
        protected Cache $cache,
    ) {
    }

    /**
     * @return array{did: string, pds: string, issuer: string, revocationEndpoint: string|null, handle: string|null}
     *
     * @throws RuntimeException when the DID names no PDS
     */
    public function resolve(string $did): array
    {
        $document = Resolver::resolveDid($did, false);
        $pds = $document->getPdsEndpoint() ?? throw new RuntimeException("{$did} names no PDS.");
        $handle = $document->getHandle();

        if ($handle !== null) {
            try {
                $handle = Resolver::handleToDid($handle) === $did ? $handle : null;
            } catch (Throwable) {
                $handle = null;
            }
        }

        $server = $this->discovery->discover($pds);
        $issuer = rtrim($server->issuer, '/');

        $this->cache->put($this->issuerKey($did), $issuer, 3600);

        return [
            'did' => $did,
            'pds' => rtrim($pds, '/'),
            'issuer' => $issuer,
            'revocationEndpoint' => $server->revocationEndpoint,
            'handle' => $handle,
        ];
    }

    /**
     * The authorization server an account signs in at, cached for an hour.
     */
    public function issuer(string $did): ?string
    {
        try {
            return $this->cache->get($this->issuerKey($did)) ?? $this->resolve($did)['issuer'];
        } catch (Throwable) {
            return null;
        }
    }

    protected function issuerKey(string $did): string
    {
        return "atp-appview:issuer:{$did}";
    }
}
