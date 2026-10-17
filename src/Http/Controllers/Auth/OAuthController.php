<?php

namespace SocialDept\AtpAppview\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use SocialDept\AtpAppview\Auth\AccountResolver;
use SocialDept\AtpClient\Auth\ClientAssertionManager;
use SocialDept\AtpClient\Auth\OAuthEngine;
use Throwable;

/**
 * The server's half of frontend-mode OAuth. The browser holds a
 * non-extractable DPoP key and pushes the authorization request, exchanges
 * the code and refreshes itself. The server keeps the PKCE verifier between
 * the redirect and the callback, signs short-lived client assertions so the
 * app stays a confidential client, and resolves accounts. It never sees an
 * OAuth token.
 */
class OAuthController extends Controller
{
    /**
     * The flow between prepare and callback: state, verifier, issuer, and
     * the handle or DID it is expected to land on.
     */
    public const FLOW_KEY = 'atp_oauth_flow';

    /**
     * A flow past its callback, waiting for the browser to collect it.
     */
    public const PENDING_KEY = 'atp_oauth_pending';

    /**
     * The issuer of a flow past its callback, until a session starts.
     */
    public const EXCHANGE_KEY = 'atp_oauth_exchange';

    /**
     * Prepare a sign-in: at a provider's server for a button, at the
     * handle's own PDS for a handle, and at the account's own PDS to sign a
     * known account back in by its DID. Answers what the browser needs to
     * push the authorization request with its own key.
     */
    public function prepare(Request $request, OAuthEngine $oauth): JsonResponse
    {
        $providers = collect(config('atp-appview.auth.frontend.providers', []))->keyBy('key');

        $validated = $request->validate([
            'provider' => ['nullable', 'required_without_all:handle,did', Rule::in($providers->keys())],
            'handle' => ['nullable', 'required_without_all:provider,did', 'string', 'max:253'],
            'did' => ['nullable', 'required_without_all:provider,handle', 'string', 'starts_with:did:', 'max:255'],
            'create' => ['nullable', 'boolean'],
        ]);

        $handle = isset($validated['handle']) ? strtolower(ltrim(trim($validated['handle']), '@')) : null;
        $did = $validated['did'] ?? null;
        $identifier = $did ?? $handle;

        try {
            $flow = $identifier !== null
                ? $oauth->prepareAuthorization($identifier)
                : $oauth->prepareAuthorization('', pdsEndpoint: $providers[$validated['provider']]['server']);
        } catch (Throwable $e) {
            Log::warning('Could not prepare sign-in', ['identifier' => $identifier, 'provider' => $validated['provider'] ?? null, 'error' => $e->getMessage()]);

            return response()->json(['message' => $identifier !== null
                ? "Could not find {$identifier}. Check the handle and try again."
                : 'That provider is not answering. Try again in a moment.'], 422);
        }

        $request->session()->put(self::FLOW_KEY, [
            'state' => $flow['state'],
            'codeVerifier' => $flow['codeVerifier'],
            'issuer' => $flow['issuer'],
            'tokenEndpoint' => $flow['tokenEndpoint'],
            'redirectUri' => $flow['redirectUri'],
            'clientId' => $flow['clientId'],
            'handle' => $handle,
            'did' => $did,
        ]);

        return response()->json([
            'state' => $flow['state'],
            'codeChallenge' => $flow['codeChallenge'],
            'codeChallengeMethod' => $flow['codeChallengeMethod'],
            'scope' => $flow['scopes'],
            'parEndpoint' => $flow['parEndpoint'],
            'authorizationEndpoint' => $flow['authorizationEndpoint'],
            'issuer' => $flow['issuer'],
            'redirectUri' => $flow['redirectUri'],
            'clientId' => $flow['clientId'],
            'loginHint' => $identifier,
            'prompt' => ($validated['create'] ?? false) ? 'create' : null,
        ]);
    }

    /**
     * A short-lived client assertion for one authorization server. Only for
     * the flow in progress, or the signed-in user's own server when the
     * browser refreshes, so no other page can borrow the client's identity.
     */
    public function clientAssertion(Request $request, ClientAssertionManager $assertions, AccountResolver $accounts): JsonResponse
    {
        $issuer = rtrim((string) $request->validate(['issuer' => ['required', 'string', 'url']])['issuer'], '/');

        $allowed = array_map(fn (string $allowed) => rtrim($allowed, '/'), array_filter([
            $request->session()->get(self::FLOW_KEY.'.issuer'),
            $request->session()->get(self::EXCHANGE_KEY),
            ($did = $this->signedInDid()) !== null ? $accounts->issuer($did) : null,
        ]));

        if (! in_array($issuer, $allowed, true)) {
            return response()->json(['message' => 'No sign-in is in progress for that server.'], 403);
        }

        return response()->json($assertions->getAuthParams($issuer));
    }

    /**
     * The redirect back from the authorization server. The code waits in
     * the session for the browser, which exchanges it with the key it pushed
     * the request with, so neither the code nor the verifier lands in a URL
     * or a page.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->failed($request->string('error_description')->toString() ?: 'Sign-in was canceled.');
        }

        $flow = $request->session()->pull(self::FLOW_KEY);

        if (! is_array($flow) || ! $request->filled(['code', 'state'])) {
            return $this->failed('That sign-in link has expired. Try again.');
        }

        if (! hash_equals($flow['state'], $request->string('state')->toString())) {
            return $this->failed('That sign-in did not start here. Try again.');
        }

        // The server names itself on the redirect (RFC 9207). Another issuer
        // than the one the flow started at is a mix-up attack.
        if ($request->filled('iss') && rtrim($request->string('iss')->toString(), '/') !== rtrim($flow['issuer'], '/')) {
            return $this->failed('That sign-in came back from the wrong server. Try again.');
        }

        $request->session()->put(self::EXCHANGE_KEY, $flow['issuer']);
        $request->session()->put(self::PENDING_KEY, [...$flow, 'code' => $request->string('code')->toString()]);

        return redirect()->to($this->routeOr('complete_route', '/'));
    }

    /**
     * Hand the browser the flow it came back with, once.
     */
    public function flow(Request $request): JsonResponse
    {
        $flow = $request->session()->pull(self::PENDING_KEY);

        if (! is_array($flow)) {
            return response()->json(['message' => 'That sign-in has expired. Try again.'], 410);
        }

        return response()->json($flow)->header('Cache-Control', 'no-store');
    }

    /**
     * An account as the server resolves it. The browser checks a token
     * response against this. Only during a sign-in or for the signed-in
     * user, so it cannot be used to make the server fetch arbitrary
     * identities.
     */
    public function account(Request $request, AccountResolver $accounts): JsonResponse
    {
        $did = (string) $request->validate(['did' => ['required', 'string', 'starts_with:did:', 'max:255']])['did'];

        if (! $request->session()->has(self::EXCHANGE_KEY) && $this->signedInDid() !== $did) {
            return response()->json(['message' => 'No sign-in is in progress.'], 403);
        }

        try {
            return response()->json($accounts->resolve($did));
        } catch (Throwable) {
            return response()->json(['message' => "Could not resolve {$did}."], 422);
        }
    }

    protected function signedInDid(): ?string
    {
        $user = Auth::guard(config('atp-appview.auth.session_guard', 'web'))->user();
        $did = data_get($user, config('atp-appview.auth.did_column', 'did'));

        return is_string($did) ? $did : null;
    }

    protected function failed(string $message): RedirectResponse
    {
        return redirect()->to($this->routeOr('login_route', '/'))->withErrors(['handle' => $message]);
    }

    protected function routeOr(string $key, string $fallback): string
    {
        $name = config("atp-appview.auth.frontend.{$key}");

        return is_string($name) && Route::has($name) ? route($name) : url($fallback);
    }
}
