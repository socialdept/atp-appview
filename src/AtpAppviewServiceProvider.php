<?php

namespace SocialDept\AtpAppview;

use CoInfra\Image\UrlBuilder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialDept\AtpAppview\Auth\AtpGuard;
use SocialDept\AtpAppview\Auth\FrontendAuth;
use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Blocklist\PdsLookup;
use SocialDept\AtpAppview\Console\BackfillCommand;
use SocialDept\AtpAppview\Console\BackfillWorkCommand;
use SocialDept\AtpAppview\Console\BlockCommand;
use SocialDept\AtpAppview\Console\BlocksCommand;
use SocialDept\AtpAppview\Console\ConsumeCommand;
use SocialDept\AtpAppview\Console\DiscoverCommand;
use SocialDept\AtpAppview\Console\FailedDeliveriesCommand;
use SocialDept\AtpAppview\Console\FieldsCommand;
use SocialDept\AtpAppview\Console\HooksApplyCommand;
use SocialDept\AtpAppview\Console\JetstreamCommand;
use SocialDept\AtpAppview\Console\LabelsStreamCommand;
use SocialDept\AtpAppview\Console\LexiconsAcceptCommand;
use SocialDept\AtpAppview\Console\LexiconsCommand;
use SocialDept\AtpAppview\Console\LexiconsSyncCommand;
use SocialDept\AtpAppview\Console\PurgeInactiveCommand;
use SocialDept\AtpAppview\Console\ReconcileCommand;
use SocialDept\AtpAppview\Console\ResortCommand;
use SocialDept\AtpAppview\Console\RestartCommand;
use SocialDept\AtpAppview\Console\RoutesCommand;
use SocialDept\AtpAppview\Console\StatsCommand;
use SocialDept\AtpAppview\Console\UnblockCommand;
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Http\Controllers\DidDocumentController;
use SocialDept\AtpAppview\Http\Controllers\TapWebhookController;
use SocialDept\AtpAppview\Http\Middleware\ResolveViewer;
use SocialDept\AtpAppview\Images\BlobUrls;
use SocialDept\AtpAppview\Images\InfraCoopBlobUrls;
use SocialDept\AtpAppview\Images\PdsBlobUrls;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Ingest\Cursors;
use SocialDept\AtpAppview\Ingest\FailedDeliveries;
use SocialDept\AtpAppview\Ingest\Heartbeats;
use SocialDept\AtpAppview\Ingest\IngestStats;
use SocialDept\AtpAppview\Ingest\Jetstream\JetstreamSource;
use SocialDept\AtpAppview\Ingest\Labels\LabelQuery;
use SocialDept\AtpAppview\Ingest\Labels\LabelSource;
use SocialDept\AtpAppview\Ingest\Labels\LabelVerifier;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use SocialDept\AtpAppview\Ingest\TapConsumer;
use SocialDept\AtpAppview\Lexicons\LexiconFollower;
use SocialDept\AtpAppview\Profiles\Profiles;
use SocialDept\AtpAppview\Spaces\Contracts\SpaceAccess;
use SocialDept\AtpAppview\Spaces\SpaceGrants;
use SocialDept\AtpAppview\Xrpc\ServiceIdentity;
use SocialDept\AtpAppview\Xrpc\XrpcRegistry;
use SocialDept\AtpAppview\Xrpc\XrpcRoutes;
use SocialDept\AtpClient\Auth\ServiceAuth;

class AtpAppviewServiceProvider extends ServiceProvider
{
    /**
     * The package's config: atp-appview.php for what is global, and a file
     * per feature in config/atp-appview/ (atp-appview.profiles from
     * profiles.php and so on), which is how an app's own config is laid out
     * too. Laravel loads an app's config/atp-appview/*.php as those keys.
     *
     * Defaults merge one level deeper than mergeConfigFrom(): a setting added
     * to a section in a later release reaches an app whose published config
     * predates it, instead of the app's section replacing the whole of ours.
     */
    protected function mergeConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $defaults = require __DIR__.'/../config/atp-appview.php';

        foreach (glob(__DIR__.'/../config/atp-appview/*.php') ?: [] as $file) {
            $defaults[basename($file, '.php')] = require $file;
        }

        $app = (array) $config->get('atp-appview', []);

        foreach ($defaults as $section => $values) {
            $mine = $app[$section] ?? null;

            // A section the app sets as a whole list, such as views, is the
            // app's alone. A keyed section takes our defaults for keys it lacks.
            $app[$section] = is_array($mine) && is_array($values) && ! array_is_list($values) && ! array_is_list($mine)
                ? array_merge($values, $mine)
                : ($mine ?? $values);
        }

        $config->set('atp-appview', $app);
    }

    public function register(): void
    {
        $this->mergeConfig();

        // Features load the first time they are asked for, at boot at the
        // latest, once every config value an app or test sets is in place.
        $this->app->singleton(AppView::class, function (Application $app) {
            $appview = new AppView();
            $appview->load(Features::all(), $app);

            return $appview;
        });

        $this->app->singleton(TapConsumer::class, fn (Application $app) => new TapConsumer(
            handlers: Pipeline::handlers($app),
            url: config('atp-appview.ingest.url'),
            password: config('atp-appview.ingest.password'),
            batchSize: (int) config('atp-appview.ingest.batch_size', 200),
            flushMs: (int) config('atp-appview.ingest.flush_ms', 500),
            maxAttempts: (int) config('atp-appview.ingest.max_attempts', 3),
            blocklist: $app->make(Blocklist::class),
            stats: $app->make(IngestStats::class),
            failedDeliveries: $app->make(FailedDeliveries::class),
        ));

        $this->app->singleton(JetstreamSource::class, fn (Application $app) => new JetstreamSource(
            handlers: Pipeline::handlers($app),
            cursors: $app->make(Cursors::class),
            url: (string) config('atp-appview.ingest.jetstream.url', 'wss://jetstream.us-east.bsky.network'),
            batchSize: (int) config('atp-appview.ingest.batch_size', 200),
            maxAttempts: (int) config('atp-appview.ingest.max_attempts', 3),
            knownRefreshSeconds: (int) config('atp-appview.ingest.jetstream.known_refresh', 60),
            blocklist: $app->make(Blocklist::class),
            stats: $app->make(IngestStats::class),
            failedDeliveries: $app->make(FailedDeliveries::class),
        ));

        $this->app->singleton(LabelVerifier::class);
        $this->app->bind(LabelQuery::class, fn () => new LabelQuery((int) config('atp-appview.labels.streams.query_rate', 4)));

        $this->app->singleton(LabelSource::class, fn (Application $app) => new LabelSource(
            handlers: Pipeline::handlers($app),
            cursors: $app->make(Cursors::class),
            verifier: $app->make(LabelVerifier::class),
            batchSize: (int) config('atp-appview.ingest.batch_size', 200),
            maxAttempts: (int) config('atp-appview.ingest.max_attempts', 3),
            refreshSeconds: (int) config('atp-appview.labels.streams.refresh', 60),
            blocklist: $app->make(Blocklist::class),
            stats: $app->make(IngestStats::class),
            failedDeliveries: $app->make(FailedDeliveries::class),
        ));

        $this->app->singleton(IngestStats::class, fn (Application $app) => new IngestStats($app->make('cache.store')));
        $this->app->singleton(Heartbeats::class, fn (Application $app) => new Heartbeats($app->make('cache.store')));

        // Policies are read once and kept for the process, re-read every
        // half minute so workers see an operator's change.
        $this->app->singleton(\SocialDept\AtpAppview\Labels\Policies::class);

        // Each viewer's label preferences are read once per request or job.
        $this->app->scoped(\SocialDept\AtpAppview\Labels\LabelPreferences::class);

        $this->app->singleton(PdsLookup::class, fn () => new PdsLookup(
            plcDirectory: (string) config('atp-support.plc_directory', 'https://plc.directory'),
            ttl: (int) config('atp-appview.blocklist.pds_ttl', 86400),
        ));

        $this->app->singleton(Blocklist::class);

        $this->app->singleton(XrpcRegistry::class);

        // Who may read a space, unless the app answers itself.
        $this->app->bindIf(SpaceAccess::class, SpaceGrants::class);

        $this->app->singleton(RecordValues::class);

        // One list of profile sources, so fluent changes at boot stick.
        $this->app->singleton(Profiles::class);

        $this->app->singleton(BlobUrls::class, fn () => config('atp-appview.images.driver') === 'pds'
            ? new PdsBlobUrls()
            : new InfraCoopBlobUrls(new UrlBuilder((string) config('atp-appview.images.url', UrlBuilder::DEFAULT_BASE))));

        $this->app->singleton(TapAdmin::class, fn () => new TapAdmin(
            config('atp-appview.ingest.admin_url'),
            config('atp-appview.ingest.password'),
        ));
    }

    public function boot(): void
    {
        // Followed lexicons are a source like any other, after the app's own.
        config()->set('atp-schema.sources', array_values(array_unique([
            ...(array) config('atp-schema.sources', []),
            LexiconFollower::path(),
        ])));

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/atp-appview.php' => config_path('atp-appview.php'),
                __DIR__.'/../config/atp-appview' => config_path('atp-appview'),
            ], 'atp-appview-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'atp-appview-migrations');

            $this->commands([
                BackfillCommand::class,
                BackfillWorkCommand::class,
                BlockCommand::class,
                BlocksCommand::class,
                ConsumeCommand::class,
                JetstreamCommand::class,
                LabelsStreamCommand::class,
                FailedDeliveriesCommand::class,
                DiscoverCommand::class,
                LexiconsAcceptCommand::class,
                LexiconsCommand::class,
                LexiconsSyncCommand::class,
                PurgeInactiveCommand::class,
                FieldsCommand::class,
                HooksApplyCommand::class,
                ReconcileCommand::class,
                ResortCommand::class,
                RestartCommand::class,
                RoutesCommand::class,
                StatsCommand::class,
                UnblockCommand::class,
            ]);

            $this->commands($this->app->make(AppView::class)->commandClasses());
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom($this->app->make(AppView::class)->migrationPaths());

        foreach ($this->app->make(AppView::class)->listeners() as [$event, $listener]) {
            Event::listen($event, $listener);
        }

        $this->registerGuard();

        $this->app->make(Router::class)->aliasMiddleware('atp.viewer', ResolveViewer::class);

        if (! $this->app->routesAreCached()) {
            $this->app->make(XrpcRoutes::class)->register();

            // Off by default: apps that run appview:consume do not need it.
            if (config('atp-appview.ingest.webhook.enabled')) {
                $this->app->make(Router::class)
                    ->post((string) config('atp-appview.ingest.webhook.path', 'atp-appview/tap'), TapWebhookController::class)
                    ->middleware((array) config('atp-appview.ingest.webhook.middleware', []))
                    ->name('atp-appview.tap-webhook');
            }

            foreach ($this->app->make(AppView::class)->routeCallbacks() as $routes) {
                $routes($this->app->make(Router::class));
            }

            // Read when the config is loaded, so route:cache keeps the choice.
            if ($this->app->make(ServiceIdentity::class)->publishable()) {
                $this->app->make(Router::class)->get('.well-known/did.json', DidDocumentController::class)->name('atp-appview.did-document');
            }

            if (FrontendAuth::enabled()) {
                $this->app->make(FrontendAuth::class)->register();
                $this->app->booted(fn () => $this->app->make(FrontendAuth::class)->registerViews());
            }
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
