<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\DeployedConfig;
use SocialDept\AtpAppview\Ingest\FailedDeliveries;
use SocialDept\AtpAppview\Ingest\IngestStats;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Ingest\TapConsumer;
use SocialDept\AtpAppview\Labels\Tack;

/**
 * Reads Tack's websocket, for a Tack without TACK_WEBHOOK_URL, the way
 * appview:consume reads Tap's.
 */
class LabelsConsumeCommand extends Command
{
    protected $signature = 'appview:labels:consume
                            {--max-time=0 : Seconds to run before exiting, 0 for no limit}
                            {--max-batches=0 : Batches to handle before exiting, 0 for no limit}
                            {--memory=128 : Megabytes of memory to allow before exiting, 0 for no limit}';

    protected $description = 'Consume labels from Tack over its websocket';

    public function handle(Cache $cache): int
    {
        $channel = Tack::channelUrl();

        if ($channel === null) {
            $this->components->error('Set atp-appview.labels.tack.url to Tack\'s address.');

            return self::FAILURE;
        }

        $consumer = new TapConsumer(
            handlers: Pipeline::handlers(app()),
            url: $channel,
            password: config('atp-appview.labels.tack.password'),
            batchSize: (int) config('atp-appview.ingest.batch_size', 200),
            flushMs: (int) config('atp-appview.ingest.flush_ms', 500),
            maxAttempts: (int) config('atp-appview.ingest.max_attempts', 3),
            blocklist: app(Blocklist::class),
            stats: app(IngestStats::class),
            failedDeliveries: app(FailedDeliveries::class),
        );

        $this->components->info("Consuming {$channel}. Press Ctrl+C to stop.");

        $consumer
            ->withLimits((int) $this->option('max-time'), (int) $this->option('max-batches'), (int) $this->option('memory'))
            ->withRestartSignal($cache)
            ->withConfigWatch(fn () => DeployedConfig::signature(), DeployedConfig::signature())
            ->run();

        if ($consumer->stopReason() !== null) {
            $this->components->info("Stopped: {$consumer->stopReason()}.");
        }

        return self::SUCCESS;
    }
}
