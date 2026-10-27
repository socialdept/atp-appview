<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use SocialDept\AtpAppview\Ingest\Cursors;
use SocialDept\AtpAppview\Ingest\DeployedConfig;
use SocialDept\AtpAppview\Ingest\Heartbeats;
use SocialDept\AtpAppview\Ingest\Jetstream\JetstreamSource;
use SocialDept\AtpSignals\Jetstream\JetstreamStream;

class JetstreamCommand extends Command
{
    protected $signature = 'appview:jetstream
                            {--from-live : Forget the saved cursor and start at the live tip}
                            {--max-time=0 : Seconds to run before exiting, 0 for no limit}
                            {--max-batches=0 : Batches to handle before exiting, 0 for no limit}
                            {--memory=128 : Megabytes of memory to allow before exiting, 0 for no limit}';

    protected $description = 'Follow Jetstream v2 for records, identities and account status, in place of Tap';

    public function handle(Cache $cache, Cursors $cursors): int
    {
        if (! class_exists(JetstreamStream::class)) {
            $this->components->error('Jetstream needs socialdept/atp-signals: composer require socialdept/atp-signals (it needs ext-gmp).');

            return self::FAILURE;
        }

        if ($this->option('from-live')) {
            $cursors->forget(JetstreamSource::CURSOR);
        }

        $source = $this->laravel->make(JetstreamSource::class);
        $cursor = $cursors->get(JetstreamSource::CURSOR);

        $this->components->info('Following '.config('atp-appview.ingest.jetstream.url').' from '.($cursor === null ? 'the live tip' : "seq {$cursor}").'. Press Ctrl+C to stop.');

        $source
            ->withLimits((int) $this->option('max-time'), (int) $this->option('max-batches'), (int) $this->option('memory'))
            ->withRestartSignal($cache)
            ->withHeartbeat($this->laravel->make(Heartbeats::class))
            ->withConfigWatch(fn () => DeployedConfig::signature(), DeployedConfig::signature())
            ->run();

        if ($source->stopReason() !== null) {
            $this->components->info("Stopped: {$source->stopReason()}.");
        }

        return self::SUCCESS;
    }
}
