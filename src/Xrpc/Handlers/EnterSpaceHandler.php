<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use InvalidArgumentException;
use SocialDept\AtpAppview\Spaces\SpaceGrants;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;
use SocialDept\AtpSpaces\Exceptions\CredentialException;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * Lets the viewer into a followed space when their session lives in the
 * browser, served under atp-appview.spaces.xrpc.enter. The browser asks its
 * PDS for a delegation token for the space and hands it over, and the
 * AppView exchanges it for a credential of its own. The token must name the
 * viewer, who comes from a Laravel session or a service auth token.
 *
 * Input {space, delegationToken}, answers {space, expiresAt}, when the grant
 * lapses unless the viewer enters again.
 */
class EnterSpaceHandler
{
    public function __construct(
        protected SpaceGrants $grants,
    ) {
    }

    /**
     * @return array{space: string, expiresAt: string}
     */
    public function __invoke(XrpcRequest $request): array
    {
        $viewer = $request->viewer();

        if ($viewer === null || $viewer->isService()) {
            throw new XrpcException('AuthenticationRequired', 'Entering a space takes a signed-in account.', 401);
        }

        $input = (array) $request->input();
        $space = is_string($input['space'] ?? null) ? SpaceRef::tryParse($input['space']) : null;
        $token = $input['delegationToken'] ?? null;

        if ($space === null || ! is_string($token) || $token === '') {
            throw XrpcException::invalidRequest('The input needs a space URI and a delegationToken for it.');
        }

        try {
            $expires = $this->grants->enterFromDelegation($space, $viewer->did, $token);
        } catch (InvalidArgumentException $e) {
            throw new XrpcException('SpaceNotFound', $e->getMessage(), 404);
        } catch (CredentialException $e) {
            throw match (true) {
                $e->isRetryable() => new XrpcException('InvalidDelegationToken', $e->getMessage(), 400),
                $e->isSpaceDeleted() => new XrpcException('SpaceDeleted', $e->getMessage(), 404),
                $e->isUserRefusal(), $e->isAppRefusal(), $e->isRevoked() => new XrpcException((string) $e->error, $e->getMessage(), 403),
                default => new XrpcException('UpstreamFailure', $e->getMessage(), 502),
            };
        }

        return ['space' => (string) $space, 'expiresAt' => $expires->toIso8601ZuluString()];
    }
}
