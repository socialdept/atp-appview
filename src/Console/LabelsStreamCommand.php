<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Ingest\DeployedConfig;
use SocialDept\AtpAppview\Ingest\Heartbeats;
use SocialDept\AtpAppview\Ingest\Labels\LabelSource;
use SocialDept\AtpSignals\Labels\LabelStream;

class LabelsStreamCommand extends Command
{
    protected $signature = 'appview:labels:stream
                            {--from-live : Forget the saved cursors and start at each labeler\'s current label}
                            {--max-time=0 : Seconds to run before exiting, 0 for no limit}
                            {--max-batches=0 : Batches to handle before exiting, 0 for no limit}
                            {--memory=128 : Megabytes of memory to allow before exiting, 0 for no limit}';

    protected $description = 'Follow each labeler\'s own label stream, in place of Tack';

    public function handle(Cache $cache): int
    {
        if (! class_exists(LabelStream::class) || ! method_exists(LabelStream::class, 'open')) {
            $this->components->error('Label streams need socialdept/atp-signals ^2.3.3: composer require socialdept/atp-signals (it needs ext-gmp).');

            return self::FAILURE;
        }

        if ($this->option('from-live')) {
            DB::connection(config('atp-appview.index.connection'))->table('atp_ingest_cursors')
                ->where('source', 'like', LabelSource::CURSOR_PREFIX.'%')->delete();
        }

        $source = $this->laravel->make(LabelSource::class);

        $this->components->info('Following the labelers in atp_labelers. Press Ctrl+C to stop.');

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
