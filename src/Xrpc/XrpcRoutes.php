<?php

namespace SocialDept\AtpAppview\Xrpc;

use Illuminate\Routing\Router;
use SocialDept\AtpAppview\Http\Controllers\XrpcController;

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

        // Both verbs, because which one a method takes comes from its lexicon,
        // and lexicons are not loaded at boot. The controller refuses the
        // wrong one.
        foreach ($this->registry->routes() as $nsid => $route) {
            $this->router
                ->match(['GET', 'POST'], "{$prefix}/{$nsid}", [XrpcController::class, 'handle'])
                ->defaults('nsid', $nsid)
                ->middleware([...$middleware, ...$this->authMiddleware($nsid, $route['auth'])])
                ->name("atp-appview.xrpc.{$nsid}");
        }

        if (config('atp-appview.xrpc.fallback', true)) {
            $this->router
                ->any("{$prefix}/{nsid}", [XrpcController::class, 'fallback'])
                ->where('nsid', '[a-zA-Z0-9.-]+')
                ->middleware($middleware)
                ->name('atp-appview.xrpc.fallback')
                // Matched after every other route, whenever it was registered,
                // so the application's own /xrpc routes still win.
                ->fallback();
        }
    }

    /**
     * @return array<int, string>
     */
    protected function authMiddleware(string $nsid, Auth $auth): array
    {
        return $auth === Auth::None ? [] : ["atp.viewer:{$auth->value},{$nsid}"];
    }
}
