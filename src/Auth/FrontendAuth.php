<?php

namespace SocialDept\AtpAppview\Auth;

use Closure;
use Illuminate\Routing\Router;
use SocialDept\AtpAppview\Http\Controllers\Auth\OAuthController;
use SocialDept\AtpAppview\Http\Controllers\Auth\SessionController;
use SocialDept\AtpAppview\Http\Controllers\Auth\SignInViewController;

/**
 * Frontend-mode sign-in: the routes the browser library talks to, the
 * settings it needs from the server, and the two pages around it.
 */
class FrontendAuth
{
    protected static ?Closure $loginView = null;

    protected static ?Closure $completeView = null;

    public function __construct(protected Router $router)
    {
    }

    /**
     * Render the login page. The package registers it as the login route,
     * for guests, and returns whatever the callback returns: an Inertia
     * response, a view, anything a route may return.
     *
     *     FrontendAuth::loginView(fn () => Inertia::render('auth/Login', [
     *         'providers' => FrontendAuth::providers(),
     *     ]));
     */
    public static function loginView(Closure $view): void
    {
        static::$loginView = $view;
    }

    /**
     * Render the page the callback sends the browser to, which runs the
     * browser library's handleCallback().
     */
    public static function completeView(Closure $view): void
    {
        static::$completeView = $view;
    }

    /**
     * @internal
     */
    public static function view(string $page): ?Closure
    {
        return $page === 'login' ? static::$loginView : static::$completeView;
    }

    /**
     * @internal
     */
    public static function flushViews(): void
    {
        static::$loginView = null;
        static::$completeView = null;
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

    /**
     * The pages an app asked the package to serve. Runs once every provider
     * has booted, since apps set their views in their own providers.
     */
    public function registerViews(): void
    {
        $group = $this->router
            ->middleware(config('atp-appview.auth.frontend.middleware', ['web']))
            ->prefix(config('atp-appview.auth.frontend.prefix', ''));

        if (static::$loginView !== null) {
            $group->get('login', [SignInViewController::class, 'login'])
                ->middleware('guest:'.config('atp-appview.auth.session_guard', 'web'))
                ->name(config('atp-appview.auth.frontend.login_route', 'login'));
        }

        if (static::$completeView !== null) {
            $group->get('login/complete', [SignInViewController::class, 'complete'])
                ->name(config('atp-appview.auth.frontend.complete_route', 'login.complete'));
        }
    }
}
