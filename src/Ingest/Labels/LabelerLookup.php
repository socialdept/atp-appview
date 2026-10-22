<?php

namespace SocialDept\AtpAppview\Ingest\Labels;

use RuntimeException;
use SocialDept\AtpClient\Exceptions\AtpResponseException;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSupport\Facades\Resolver;

/**
 * What the app needs to follow a labeler, from its DID document and its
 * app.bsky.labeler.service declaration.
 */
class LabelerLookup
{
    /**
     * @return array{endpoint: string, key: string}
     *
     * @throws RuntimeException when the DID document names no labeler service or label key
     */
    public function service(string $did, bool $fresh = false): array
    {
        $document = Resolver::resolveDid($did, ! $fresh);
        $endpoint = null;

        foreach ($document->service as $service) {
            $id = (string) ($service['id'] ?? '');

            if (($id === '#atproto_labeler' || str_ends_with($id, '#atproto_labeler')) && is_string($service['serviceEndpoint'] ?? null)) {
                $endpoint = rtrim($service['serviceEndpoint'], '/');
            }
        }

        return [
            'endpoint' => $endpoint ?? throw new RuntimeException("{$did} names no labeler service."),
            'key' => $document->getSigningKey('#atproto_label') ?? throw new RuntimeException("{$did} publishes no label key."),
        ];
    }

    /**
     * The labeler's declaration, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function declaration(string $did): ?array
    {
        $pds = Atp::public(Resolver::resolvePds($did) ?? throw new RuntimeException("{$did} has no PDS."));

        try {
            $value = $pds->atproto->repo->getRecord($did, 'app.bsky.labeler.service', 'self')->value;
        } catch (AtpResponseException $e) {
            if ($e->errorCode === 'RecordNotFound') {
                return null;
            }

            throw $e;
        }

        return is_array($value) ? $value : null;
    }
}
