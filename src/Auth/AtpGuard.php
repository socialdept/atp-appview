<?php

namespace SocialDept\AtpAppview\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use SocialDept\AtpClient\Auth\ServiceAuth;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpClient\Exceptions\ServiceAuthException;

/**
 * Resolves the viewer of a request from a service auth JWT or the session.
 *
 * In order:
 *
 * 1. A bearer JWT is verified as service auth: signature against the issuer's
 *    DID document, expiry, audience, and the route's method as `lxm`. A
 *    bearer that fails is an error and never falls back to the session.
 * 2. Otherwise the configured session guard supplies the user.
 * 3. Otherwise the viewer is anonymous.
 *
 * It wraps the session guard rather than replacing it, so the application's
 * login flow, remember cookies and Sanctum stateful requests keep working.
 */
class AtpGuard implements Guard
{
    /**
     * Viewers already resolved for this request, keyed by expected method.
     *
     * @var array<string, Viewer|null>
     */
    protected array $resolved = [];

    protected ?Authenticatable $explicitUser = null;

    /**
     * @param  array<string, mixed>  $config  The guard's auth.guards entry
     */
    public function __construct(
        protected UserProvider $provider,
        protected Request $request,
        protected AuthFactory $auth,
        protected ServiceAuth $serviceAuth,
        protected array $config = [],
    ) {
    }

    /**
     * The viewer of the current request.
     *
     * `$method` is the NSID the route answers to. A service auth token is only
     * accepted when it is given, since without it the token's `lxm` binding
     * could not be checked.
     *
     * @throws ServiceAuthException When a bearer token is present but invalid
     */
    public function viewer(?string $method = null): ?Viewer
    {
        $key = $method ?? '';

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $token = $this->serviceAuth->fromHeader($this->request->header('Authorization'));

        return $this->resolved[$key] = $token !== null
            ? $this->viewerFromServiceAuth($token, $method)
            : $this->viewerFromSession();
    }

    public function user(): ?Authenticatable
    {
        if ($this->explicitUser !== null) {
            return $this->explicitUser;
        }

        try {
            return $this->viewer()?->user();
        } catch (ServiceAuthException) {
            return null;
        }
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): mixed
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function hasUser(): bool
    {
        return $this->explicitUser !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->explicitUser = $user;

        return $this;
    }

    /**
     * Credentials are never validated here: identity comes from a verified
     * token or the session guard.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function setRequest(Request $request): static
    {
        $this->request = $request;
        $this->resolved = [];

        return $this;
    }

    protected function viewerFromServiceAuth(string $token, ?string $method): Viewer
    {
        $this->refuseAccessTokens($token);

        if ($method === null) {
            throw new ServiceAuthException('This route does not accept service auth tokens.', 'BadJwtLexiconMethod', 401);
        }

        $audience = config('atp-client.service_auth.audience');
        $audience = is_string($audience) && $audience !== '' ? $audience : null;

        if ($audience === null && ! config('atp-client.service_auth.allow_any_audience', false)) {
            throw ServiceAuthException::unconfigured();
        }

        $verified = $this->serviceAuth->verify($token, $audience, $method);

        [$did, $serviceId] = array_pad(explode('#', $verified->issuer, 2), 2, null);

        if ($serviceId !== null) {
            if (! in_array($verified->issuer, (array) config('atp-appview.auth.trusted_services', []), true)) {
                throw ServiceAuthException::issuer("{$verified->issuer} is not a trusted service");
            }

            return new Viewer($did, ViewerSource::ServiceAuth, $verified->method, $serviceId);
        }

        return new Viewer($did, ViewerSource::ServiceAuth, $verified->method, null, $this->provision($did));
    }

    protected function viewerFromSession(): ?Viewer
    {
        $user = $this->auth->guard($this->sessionGuard())->user();

        if (! $user instanceof HasAtpSession) {
            return null;
        }

        $did = $user->getAtpDid();

        return is_string($did) && $did !== '' ? new Viewer($did, ViewerSource::Session, null, null, $user) : null;
    }

    /**
     * An OAuth access token belongs at the PDS. Sent here by mistake, it must
     * be refused rather than misread as service auth.
     */
    protected function refuseAccessTokens(string $token): void
    {
        $header = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);
        $type = is_array($header) ? strtolower((string) ($header['typ'] ?? '')) : '';

        if (in_array($type, ['at+jwt', 'dpop+jwt'], true)) {
            throw new ServiceAuthException('OAuth access tokens are not accepted here, send a service auth token.', 'InvalidToken', 401);
        }
    }

    /**
     * The local user for a verified DID, created on first sight when
     * provisioning is on. Users are only ever created from a verified
     * credential, never from a DID in a request body.
     */
    protected function provision(string $did): ?Authenticatable
    {
        $column = (string) config('atp-appview.auth.did_column', 'did');
        $existing = $this->provider->retrieveByCredentials([$column => $did]);

        if ($existing !== null || config('atp-appview.auth.provision', 'create') !== 'create') {
            return $existing;
        }

        if (! $this->provider instanceof EloquentUserProvider) {
            return null;
        }

        return $this->provider->createModel()->newQuery()->firstOrCreate([$column => $did]);
    }

    protected function sessionGuard(): string
    {
        return (string) ($this->config['session_guard'] ?? config('atp-appview.auth.session_guard', 'web'));
    }
}
