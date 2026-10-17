<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use SocialDept\AtpAppview\Ingest\TapConsumer;

class ConsumeCommand extends Command
{
    protected $signature = 'appview:consume
                            {--max-time=0 : Seconds to run before exiting, 0 for no limit}
                            {--max-batches=0 : Batches to handle before exiting, 0 for no limit}
                            {--memory=128 : Megabytes of memory to allow before exiting, 0 for no limit}';

    protected $description = 'Consume events from Tap in batches and hand them to the ingest handlers';

    public function handle(TapConsumer $consumer, Cache $cache): int
    {
        if (config('atp-appview.ingest.handlers', []) === []) {
            $this->components->warn('No ingest handlers are configured, so events will be acknowledged and dropped.');
        }

        $this->components->info('Consuming '.config('atp-appview.ingest.url').'. Press Ctrl+C to stop.');

        $consumer
            ->withLimits((int) $this->option('max-time'), (int) $this->option('max-batches'), (int) $this->option('memory'))
            ->withRestartSignal($cache)
            ->run();

        if ($consumer->stopReason() !== null) {
            $this->components->info("Stopped: {$consumer->stopReason()}.");
        }

        return self::SUCCESS;
    }
}
