<?php

namespace SocialDept\AtpAppview\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use SocialDept\AtpAppview\Auth\AtpGuard;
use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpClient\Exceptions\ServiceAuthException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the viewer for an XRPC route and attaches it to the request.
 *
 *     Route::get('/xrpc/com.example.getTimeline', GetTimeline::class)
 *         ->middleware('atp.viewer:required,com.example.getTimeline');
 *
 * The first argument is `required` or `optional`. The second is the NSID the
 * route answers to, which a service auth token must be bound to. Handlers
 * read the result with `$request->viewer()`.
 */
class ResolveViewer
{
    public function handle(Request $request, Closure $next, string $requirement = 'required', ?string $method = null): Response
    {
        $guard = Auth::guard(config('atp-appview.auth.guard', 'atp'));

        if (! $guard instanceof AtpGuard) {
            return $this->error(500, 'InternalServerError', 'The atp-appview guard is not configured: add a guard with the "atp" driver.');
        }

        try {
            $viewer = $guard->viewer($method);
        } catch (ServiceAuthException $e) {
            return $this->error($e->status, $e->error, $e->getMessage());
        }

        if ($viewer === null && $requirement !== 'optional') {
            return $this->error(401, 'AuthenticationRequired', 'Authentication required.');
        }

        $request->attributes->set(Viewer::class, $viewer);

        return $next($request);
    }

    protected function error(int $status, string $error, string $message): Response
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
