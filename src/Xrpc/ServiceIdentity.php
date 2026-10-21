<?php

namespace SocialDept\AtpAppview\Xrpc;

use SocialDept\AtpAppview\Features\AppView;

/**
 * Who this AppView is on the network: the did:web it answers to, from
 * atp-client.service_auth.audience, and where its service is reached, from
 * atp-appview.service.endpoint.
 */
class ServiceIdentity
{
    /**
     * Whether a DID document can be served: the audience is a did:web and an
     * endpoint is set.
     */
    public function publishable(): bool
    {
        return str_starts_with($this->did() ?? '', 'did:web:') && $this->endpoint() !== null;
    }

    public function did(): ?string
    {
        $audience = config('atp-client.service_auth.audience');

        return is_string($audience) && $audience !== '' ? explode('#', $audience, 2)[0] : null;
    }

    /**
     * The service id, from the audience's fragment, atproto_appview without one.
     */
    public function serviceId(): string
    {
        $audience = (string) config('atp-client.service_auth.audience');

        return str_contains($audience, '#') ? explode('#', $audience, 2)[1] : 'atproto_appview';
    }

    public function endpoint(): ?string
    {
        $endpoint = config('atp-appview.service.endpoint');

        return is_string($endpoint) && $endpoint !== '' ? rtrim($endpoint, '/') : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        $services = [$this->serviceId() => (string) config('atp-appview.service.type', 'AtprotoAppView')]
            + app(AppView::class)->services();

        return [
            '@context' => ['https://www.w3.org/ns/did/v1'],
            'id' => $this->did(),
            'service' => array_map(fn (string $id, string $type) => [
                'id' => '#'.$id,
                'type' => $type,
                'serviceEndpoint' => $this->endpoint(),
            ], array_keys($services), $services),
        ];
    }
}
