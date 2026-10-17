<?php

namespace SocialDept\AtpAppview;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use SocialDept\AtpAppview\Auth\AtpGuard;
use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Blocklist\PdsLookup;
use SocialDept\AtpAppview\Console\BlockCommand;
use SocialDept\AtpAppview\Console\BlocksCommand;
use SocialDept\AtpAppview\Console\ConsumeCommand;
use SocialDept\AtpAppview\Console\DiscoverCommand;
use SocialDept\AtpAppview\Console\RestartCommand;
use SocialDept\AtpAppview\Console\RoutesCommand;
use SocialDept\AtpAppview\Console\UnblockCommand;
use SocialDept\AtpAppview\Http\Middleware\ResolveViewer;
use SocialDept\AtpAppview\Ingest\IngestStats;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use SocialDept\AtpAppview\Ingest\TapConsumer;
use SocialDept\AtpAppview\Xrpc\XrpcRegistry;
use SocialDept\AtpAppview\Xrpc\XrpcRoutes;
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
            blocklist: $app->make(Blocklist::class),
            stats: $app->make(IngestStats::class),
        ));

        $this->app->singleton(IngestStats::class, fn (Application $app) => new IngestStats($app->make('cache.store')));

        $this->app->singleton(PdsLookup::class, fn () => new PdsLookup(
            plcDirectory: (string) config('atp-support.plc_directory', 'https://plc.directory'),
            ttl: (int) config('atp-appview.blocklist.pds_ttl', 86400),
        ));

        $this->app->singleton(Blocklist::class);

        $this->app->singleton(XrpcRegistry::class);

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

            $this->commands([
                BlockCommand::class,
                BlocksCommand::class,
                ConsumeCommand::class,
                DiscoverCommand::class,
                RestartCommand::class,
                RoutesCommand::class,
                UnblockCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerGuard();

        $this->app->make(Router::class)->aliasMiddleware('atp.viewer', ResolveViewer::class);

        if (! $this->app->routesAreCached()) {
            $this->app->make(XrpcRoutes::class)->register();
        }

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
