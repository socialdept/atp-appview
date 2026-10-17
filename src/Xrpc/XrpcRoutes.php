<?php

namespace SocialDept\AtpAppview\Xrpc;

use Illuminate\Routing\Router;
use SocialDept\AtpAppview\Http\Controllers\XrpcController;
use SocialDept\AtpAppview\Http\Middleware\VerifyXrpcCsrf;

/**
 * Registers a route for every XRPC method, plus a fallback for the rest.
 *
 * Routes point at a controller rather than closures, so `route:cache` works.
 */
class XrpcRoutes
{
    public function __construct(
        protected Router $router,
        protected XrpcRegistry $registry,
    ) {
    }

    public function register(): void
    {
        $prefix = trim((string) config('atp-appview.xrpc.prefix', 'xrpc'), '/');
        $middleware = (array) config('atp-appview.xrpc.middleware', []);
        $csrf = $this->csrfMiddleware($middleware);

        // Both verbs, because which one a method takes comes from its lexicon,
        // and lexicons are not loaded at boot. The controller refuses the
        // wrong one.
        foreach ($this->registry->routes() as $nsid => $route) {
            $registered = $this->router
                ->match(['GET', 'POST'], "{$prefix}/{$nsid}", [XrpcController::class, 'handle'])
                ->defaults('nsid', $nsid)
                ->middleware([...$middleware, ...$this->csrfReplacement($csrf), ...$this->authMiddleware($nsid, $route['auth'])])
                ->name("atp-appview.xrpc.{$nsid}");

            if ($csrf !== null) {
                $registered->withoutMiddleware($csrf);
            }
        }

        if (config('atp-appview.xrpc.fallback', true)) {
            $fallback = $this->router
                ->any("{$prefix}/{nsid}", [XrpcController::class, 'fallback'])
                ->where('nsid', '[a-zA-Z0-9.-]+')
                ->middleware([...$middleware, ...$this->csrfReplacement($csrf)])
                ->name('atp-appview.xrpc.fallback')
                // Matched after every other route, whenever it was registered,
                // so the application's own /xrpc routes still win.
                ->fallback();

            if ($csrf !== null) {
                $fallback->withoutMiddleware($csrf);
            }
        }
    }

    /**
     * The CSRF middleware the configured middleware brings in, usually through
     * the web group, or null when there is none.
     *
     * @param  array<int, mixed>  $middleware
     * @return class-string|null
     */
    protected function csrfMiddleware(array $middleware): ?string
    {
        $groups = $this->router->getMiddlewareGroups();
        $bases = [
            'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
            'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
            'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        ];

        foreach ($middleware as $entry) {
            foreach (is_string($entry) && isset($groups[$entry]) ? $groups[$entry] : [$entry] as $class) {
                if (! is_string($class) || ! class_exists($class)) {
                    continue;
                }

                foreach ($bases as $base) {
                    if (is_a($class, $base, true)) {
                        return $class;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  class-string|null  $csrf
     * @return array<int, string>
     */
    protected function csrfReplacement(?string $csrf): array
    {
        return $csrf === null ? [] : [VerifyXrpcCsrf::class.':'.$csrf];
    }

    /**
     * @return array<int, string>
     */
    protected function authMiddleware(string $nsid, Auth $auth): array
    {
        return $auth === Auth::None ? [] : ["atp.viewer:{$auth->value},{$nsid}"];
    }
}
