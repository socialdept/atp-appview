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

        foreach ($this->registry->all() as $method) {
            $this->router
                ->match([$method->httpMethod()], "{$prefix}/{$method->nsid}", [XrpcController::class, 'handle'])
                ->defaults('nsid', $method->nsid)
                ->middleware([...$middleware, ...$this->authMiddleware($method)])
                ->name("atp-appview.xrpc.{$method->nsid}");
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
    protected function authMiddleware(XrpcMethod $method): array
    {
        return $method->auth === Auth::None ? [] : ["atp.viewer:{$method->auth->value},{$method->nsid}"];
    }
}
