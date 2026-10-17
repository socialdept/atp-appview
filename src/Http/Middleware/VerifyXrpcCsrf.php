<?php

namespace SocialDept\AtpAppview\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * CSRF protection for XRPC routes, only where it means something.
 *
 * Forgery rides on credentials the browser attaches by itself, the session
 * cookie. A PDS calling a procedure on a user's behalf sends a service auth
 * bearer token and no cookie, and never has a CSRF token, so the web group's
 * check refused every such call. XrpcRoutes takes that check off its routes
 * and puts this one on instead, naming the application's own CSRF middleware,
 * which still runs, with its own exceptions, for any request that carries a
 * session cookie and no bearer token.
 */
class VerifyXrpcCsrf
{
    /**
     * @param  class-string  $csrf  The application's CSRF middleware
     */
    public function handle(Request $request, Closure $next, string $csrf): Response
    {
        if (! $this->carriesAmbientCredentials($request)) {
            return $next($request);
        }

        try {
            return app($csrf)->handle($request, $next);
        } catch (Throwable $e) {
            // OriginMismatchException exists only in newer Laravel versions.
            if (! $e instanceof TokenMismatchException && ! is_a($e, 'Illuminate\Http\Exceptions\OriginMismatchException')) {
                throw $e;
            }

            return new JsonResponse(['error' => 'CsrfTokenMismatch', 'message' => $e->getMessage()], 419);
        }
    }

    /**
     * Whether the browser could have attached this request's credentials on
     * its own: a session cookie, and no bearer token. The atp guard uses a
     * bearer token alone when one is present, never the session, and a page
     * on another site cannot set an Authorization header.
     */
    protected function carriesAmbientCredentials(Request $request): bool
    {
        if ($request->bearerToken() !== null) {
            return false;
        }

        return $request->cookies->has((string) config('session.cookie'));
    }
}
