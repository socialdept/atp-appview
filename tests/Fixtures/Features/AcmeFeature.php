<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Features;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Features\Feature;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Xrpc\Auth;

/**
 * A third party's feature, using everything a feature can register.
 */
class AcmeFeature implements Feature
{
    public static int $batches = 0;

    /** @var array<int, string> */
    public static array $written = [];

    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            ->config('acme', ['greeting' => 'hello', 'loud' => false])
            ->migrations(__DIR__.'/migrations')
            ->handler(AcmeHandler::class)
            ->afterWrite(function ($connection, array $rows) {
                array_push(self::$written, ...array_column($rows, 'uri'));
            })
            ->field('com.example.thing', 'mood', 'string')
            ->xrpc('com.acme.ping', AcmePing::class, auth: Auth::None)
            ->routes(fn (Router $router) => $router->get('acme/ping', fn () => 'pong')->name('acme.ping'))
            ->commands([AcmeCommand::class])
            ->readFilter(fn (string $viewer) => $viewer === 'did:plc:alice' ? ['did:plc:mallory'] : [])
            ->schedule(fn (Schedule $schedule) => $schedule->command('acme:tidy')->hourly());
    }
}

class AcmeHandler implements BatchHandler
{
    public function handle(Batch $batch): void
    {
        AcmeFeature::$batches++;
    }
}

class AcmePing
{
    public function __invoke(): array
    {
        return ['pong' => config('atp-appview.acme.greeting')];
    }
}

class AcmeCommand extends Command
{
    protected $signature = 'acme:tidy';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

/**
 * Needs a feature nobody turned on.
 */
class NeedyFeature implements Feature
{
    public function requires(): array
    {
        return [AcmeFeature::class];
    }

    public function register(AppView $appview): void
    {
    }
}
