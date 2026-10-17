<?php

namespace SocialDept\AtpAppview;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use SocialDept\AtpAppview\Auth\AtpGuard;
use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpAppview\Console\ConsumeCommand;
use SocialDept\AtpAppview\Console\RestartCommand;
use SocialDept\AtpAppview\Http\Middleware\ResolveViewer;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use SocialDept\AtpAppview\Ingest\TapConsumer;
use SocialDept\AtpClient\Auth\ServiceAuth;

class AtpAppviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atp-appview.php', 'atp-appview');

        $this->app->singleton(TapConsumer::class, fn (Application $app) => new TapConsumer(
            handlers: array_map(fn (string $class) => $app->make($class), config('atp-appview.ingest.handlers', [])),
            url: config('atp-appview.ingest.url'),
            password: config('atp-appview.ingest.password'),
            batchSize: (int) config('atp-appview.ingest.batch_size', 200),
            flushMs: (int) config('atp-appview.ingest.flush_ms', 500),
            maxAttempts: (int) config('atp-appview.ingest.max_attempts', 3),
        ));

        $this->app->singleton(TapAdmin::class, fn () => new TapAdmin(
            config('atp-appview.ingest.admin_url'),
            config('atp-appview.ingest.password'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/atp-appview.php' => config_path('atp-appview.php'),
            ], 'atp-appview-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'atp-appview-migrations');

            $this->commands([ConsumeCommand::class, RestartCommand::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

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
