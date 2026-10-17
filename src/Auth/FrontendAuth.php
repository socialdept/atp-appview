<?php

namespace SocialDept\AtpAppview\Auth;

use Illuminate\Routing\Router;
use SocialDept\AtpAppview\Http\Controllers\Auth\OAuthController;
use SocialDept\AtpAppview\Http\Controllers\Auth\SessionController;

/**
 * Frontend-mode sign-in: the routes the browser library talks to, and the
 * settings it needs from the server.
 */
class FrontendAuth
{
    public function __construct(protected Router $router)
    {
    }

    public static function enabled(): bool
    {
        return config('atp-appview.auth.mode', 'frontend') === 'frontend'
            && (bool) config('atp-appview.auth.frontend.routes', true);
    }

    /**
     * What the browser needs to start a server session from its own tokens.
     * Share it with every page, such as through Inertia's shared props.
     *
     * @return array{sessionMethod: string|null, audience: string|null}
     */
    public static function browserConfig(): array
    {
        return [
            'sessionMethod' => config('atp-appview.auth.frontend.session_method'),
            'audience' => config('atp-client.service_auth.audience'),
        ];
    }

    /**
     * The login page's provider buttons, without the servers behind them.
     *
     * @return list<array{key: string, name: string}>
     */
    public static function providers(): array
    {
        return array_values(array_map(
            fn (array $provider) => ['key' => $provider['key'], 'name' => $provider['name']],
            config('atp-appview.auth.frontend.providers', []),
        ));
    }

    public function register(): void
    {
        $this->router
            ->middleware(config('atp-appview.auth.frontend.middleware', ['web']))
            ->prefix(config('atp-appview.auth.frontend.prefix', ''))
            ->name('atp.')
            ->group(function (Router $router) {
                $router->post('oauth/prepare', [OAuthController::class, 'prepare'])->middleware('throttle:20,1')->name('oauth.prepare');
                $router->post('oauth/client-assertion', [OAuthController::class, 'clientAssertion'])->middleware('throttle:60,1')->name('oauth.client-assertion');
                $router->get('oauth/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
                $router->post('oauth/flow', [OAuthController::class, 'flow'])->middleware('throttle:20,1')->name('oauth.flow');
                $router->get('oauth/account', [OAuthController::class, 'account'])->middleware('throttle:30,1')->name('oauth.account');
                $router->post('session', [SessionController::class, 'store'])->middleware('throttle:20,1')->name('session.store');
                $router->delete('session', [SessionController::class, 'destroy'])->name('session.destroy');
            });
    }
}
