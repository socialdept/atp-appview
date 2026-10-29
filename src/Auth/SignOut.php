<?php

namespace SocialDept\AtpAppview\Auth;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpClient\Data\RevocationResult;
use SocialDept\AtpClient\Facades\Atp;

/**
 * Signing the user out from the server, in either auth mode. Call it from
 * the app's logout route:
 *
 *     public function destroy(Request $request, SignOut $signOut)
 *     {
 *         $signOut($request);
 *
 *         return redirect('/');
 *     }
 *
 * In backend mode the server holds the atproto session, so it is revoked at
 * the authorization server and forgotten. In frontend mode the browser holds
 * it, and would quietly start a new server session from it on the next
 * page. So the next page's FrontendAuth::browserConfig() carries `signOut`,
 * and the browser library signs itself out instead.
 */
class SignOut
{
    /**
     * Flashed for the next page, which hands it to the browser.
     */
    public const FLASH_KEY = 'atp_sign_out';

    /**
     * The account just signed out in frontend mode, until the browser asks
     * for the one client assertion it needs to revoke its tokens.
     */
    public const REVOKING_KEY = 'atp_sign_out_revoking';

    /**
     * @param  string|null  $guard  The session guard to sign out of, `auth.session_guard` by default
     * @return RevocationResult|null How revoking the server's atproto session went, in backend mode
     */
    public function __invoke(Request $request, ?string $guard = null): ?RevocationResult
    {
        $did = $this->signedInDid($guard);
        $backend = config('atp-appview.auth.mode', 'frontend') === 'backend';

        // Before the session is invalidated, since a session credential
        // provider keeps the tokens in it.
        $result = $backend && $did !== null ? Atp::oauth()->revoke($did) : null;

        $this->endSession($request, $guard);

        if (! $backend && $did !== null) {
            $request->session()->flash(self::FLASH_KEY, Str::random(32));
            $request->session()->put(self::REVOKING_KEY, ['did' => $did, 'until' => now()->addMinutes(10)->getTimestamp()]);
        }

        return $result;
    }

    /**
     * End the Laravel session only, as the browser's own signOut() asks
     * once it has signed itself out.
     */
    public function endSession(Request $request, ?string $guard = null): void
    {
        $this->guard($guard)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * The account signed out in frontend mode whose browser may still need
     * a client assertion to revoke its tokens. Pulled, so it is good once.
     */
    public static function pullRevoking(Request $request): ?string
    {
        $revoking = $request->session()->pull(self::REVOKING_KEY);

        return is_array($revoking) && ($revoking['until'] ?? 0) >= now()->getTimestamp() && is_string($revoking['did'] ?? null)
            ? $revoking['did']
            : null;
    }

    protected function signedInDid(?string $guard): ?string
    {
        $user = $this->guard($guard)->user();
        $did = $user instanceof HasAtpSession
            ? $user->getAtpDid()
            : data_get($user, config('atp-appview.auth.did_column', 'did'));

        return is_string($did) && $did !== '' ? $did : null;
    }

    protected function guard(?string $guard): StatefulGuard
    {
        /** @var StatefulGuard */
        return Auth::guard($guard ?? config('atp-appview.auth.session_guard', 'web'));
    }
}
