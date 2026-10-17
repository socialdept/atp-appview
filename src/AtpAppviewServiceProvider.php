<?php

namespace SocialDept\AtpAppview;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use SocialDept\AtpAppview\Auth\AtpGuard;
use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpAppview\Http\Middleware\ResolveViewer;
use SocialDept\AtpClient\Auth\ServiceAuth;

class AtpAppviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atp-appview.php', 'atp-appview');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/atp-appview.php' => config_path('atp-appview.php'),
            ], 'atp-appview-config');
        }

        $this->registerGuard();

        $this->app->make(Router::class)->aliasMiddleware('atp.viewer', ResolveViewer::class);

        Request::macro('viewer', function (): ?Viewer {
            /** @var Request $this */
            return $this->attributes->get(Viewer::class);
        });
    }

    /**
     * Register the "atp" guard driver.
     *
     *     'guards' => [
     *         'atp' => ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web'],
     *     ],
     */
    protected function registerGuard(): void
    {
        Auth::extend('atp', function (Application $app, string $name, array $config) {
            $guard = new AtpGuard(
                Auth::createUserProvider($config['provider'] ?? null),
                $app['request'],
                $app['auth'],
                $app->make(ServiceAuth::class),
                $config,
            );

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }
}
