<?php

namespace SocialDept\AtpAppview\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use SocialDept\AtpClient\AtpClient;
use SocialDept\AtpClient\Auth\ScopeChecker;
use SocialDept\AtpClient\Contracts\CredentialProvider;
use SocialDept\AtpClient\Enums\Scope;
use SocialDept\AtpClient\Facades\Atp;

/**
 * Who is making a request, and how they proved it.
 *
 * Handlers read the DID from here whatever the auth mode, so the same
 * endpoint works for a session cookie and for a service auth token.
 */
final class Viewer
{
    public function __construct(
        public readonly string $did,
        public readonly ViewerSource $source,
        public readonly ?string $method = null,
        public readonly ?string $serviceId = null,
        protected readonly ?Authenticatable $user = null,
    ) {
    }

    /**
     * The local user model, when one exists for this DID.
     */
    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    /**
     * Whether the caller is a service, identified by a did#serviceId issuer,
     * rather than a person.
     */
    public function isService(): bool
    {
        return $this->serviceId !== null;
    }

    /**
     * Whether the server holds an OAuth or app password session for this DID,
     * and so can write to the user's repo on their behalf.
     *
     * False in frontend mode, where the browser holds the tokens: there the
     * browser writes to the repo itself and the AppView only changes its own
     * state.
     */
    public function canActOnRepo(): bool
    {
        if ($this->isService()) {
            return false;
        }

        return app(CredentialProvider::class)->getCredentials($this->did) !== null;
    }

    /**
     * A client authenticated as this viewer, or null when the server holds no
     * session for them.
     */
    public function client(): ?AtpClient
    {
        return $this->canActOnRepo() ? Atp::as($this->did) : null;
    }

    /**
     * Whether the server-held session for this viewer was granted a scope.
     *
     * Always false when the server holds no session, since a service auth
     * token carries no OAuth scopes.
     */
    public function hasScope(string|Scope $scope): bool
    {
        if (! $this->canActOnRepo()) {
            return false;
        }

        return app(ScopeChecker::class)->hasScope(Atp::as($this->did)->client->session(), $scope);
    }
}
