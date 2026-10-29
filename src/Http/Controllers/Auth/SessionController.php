<?php

namespace SocialDept\AtpAppview\Http\Controllers\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use SocialDept\AtpAppview\Auth\AccountResolver;
use SocialDept\AtpAppview\Auth\SignOut;
use SocialDept\AtpAppview\Moderation\AccountActions;
use SocialDept\AtpClient\Auth\ServiceAuth;
use SocialDept\AtpClient\Exceptions\ServiceAuthException;
use Throwable;

/**
 * Server sessions for frontend mode. The browser asks its own PDS for a
 * service auth token addressed to this app and hands it over once. The
 * token is signed with the account's repo key, so verifying it proves who is
 * signing in, and an ordinary session starts without the server ever holding
 * the user's OAuth tokens. Nothing the browser says about itself is taken on
 * trust: the handle is resolved here.
 */
class SessionController extends Controller
{
    public function store(Request $request, ServiceAuth $serviceAuth, AccountResolver $accounts): JsonResponse
    {
        $method = config('atp-appview.auth.frontend.session_method');

        if (! is_string($method) || $method === '') {
            return response()->json(['message' => 'Set atp-appview.auth.frontend.session_method to sign in.'], 500);
        }

        $jwt = $serviceAuth->fromHeader($request->header('Authorization')) ?? '';

        try {
            $token = $serviceAuth->verify($jwt, config('atp-client.service_auth.audience'), $method);
        } catch (ServiceAuthException $e) {
            return response()->json(['error' => $e->error, 'message' => $e->getMessage()], 401);
        }

        // A service signing as itself is not an account.
        if (str_contains($token->did(), '#')) {
            return response()->json(['error' => 'BadJwtIss', 'message' => 'Only accounts can sign in.'], 401);
        }

        try {
            $account = $accounts->resolve($token->did());
        } catch (Throwable) {
            return response()->json(['message' => 'Could not resolve the account.'], 422);
        }

        if (app(AccountActions::class)->banned($account['did'])) {
            return response()->json(['error' => 'AccountBanned', 'message' => 'This account cannot sign in here.'], 403);
        }

        $guard = $this->guard();
        $user = $this->user($guard, $account['did'], $account['handle']);

        if ($user === null) {
            return response()->json(['message' => 'There is no account here for you.'], 403);
        }

        $guard->login($user, (bool) config('atp-appview.auth.frontend.remember', true));
        $request->session()->regenerate();
        $request->session()->forget([OAuthController::EXCHANGE_KEY, SignOut::REVOKING_KEY]);

        return response()->json(['did' => $account['did'], 'handle' => $account['handle']]);
    }

    /**
     * The end of the browser's signOut(), which has already revoked and
     * forgotten its tokens, so only the Laravel session is left.
     */
    public function destroy(Request $request, SignOut $signOut): JsonResponse
    {
        $signOut->endSession($request);

        return response()->json(['ok' => true]);
    }

    protected function guard(): StatefulGuard
    {
        /** @var StatefulGuard */
        return Auth::guard(config('atp-appview.auth.session_guard', 'web'));
    }

    /**
     * The local user for a verified DID, created on first sign-in when
     * provisioning is on, with its handle kept current.
     */
    protected function user(StatefulGuard $guard, string $did, ?string $handle): ?Authenticatable
    {
        $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;

        if (! $provider instanceof EloquentUserProvider) {
            return null;
        }

        $didColumn = (string) config('atp-appview.auth.did_column', 'did');
        $handleColumn = config('atp-appview.auth.frontend.handle_column', 'handle');
        $user = $provider->retrieveByCredentials([$didColumn => $did]);

        if ($user === null) {
            if (config('atp-appview.auth.provision', 'create') !== 'create') {
                return null;
            }

            $user = $provider->createModel()->forceFill([$didColumn => $did]);
        }

        if (is_string($handleColumn) && $handleColumn !== '') {
            $user->forceFill([$handleColumn => $handle]);
        }

        $user->save();

        return $user;
    }
}
