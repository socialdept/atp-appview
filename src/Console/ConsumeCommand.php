<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Ingest\TapConsumer;

class ConsumeCommand extends Command
{
    protected $signature = 'appview:consume';

    protected $description = 'Consume events from Tap in batches and hand them to the ingest handlers';

    public function handle(TapConsumer $consumer): int
    {
        if (config('atp-appview.ingest.handlers', []) === []) {
            $this->components->warn('No ingest handlers are configured, so events will be acknowledged and dropped.');
        }

        $this->components->info('Consuming '.config('atp-appview.ingest.url').'. Press Ctrl+C to stop.');

        $consumer->run();

        return self::SUCCESS;
    }
}
