<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\DeployedConfig;
use SocialDept\AtpAppview\Ingest\TapConsumer;

class ConsumerConfigWatchTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
    }

    public function test_without_a_watch_the_config_never_counts_as_changed(): void
    {
        $this->assertFalse($this->consumer()->configChanged());
    }

    public function test_a_different_deployed_config_counts_as_changed(): void
    {
        $deployed = 'aaa';
        $consumer = $this->consumer()->withConfigWatch(function () use (&$deployed) {
            return $deployed;
        }, 'aaa');

        $this->assertFalse($consumer->configChanged());

        $deployed = 'bbb';
        $this->assertTrue($consumer->configChanged());
    }

    public function test_a_digest_that_cannot_be_read_is_not_a_change(): void
    {
        $consumer = $this->consumer()->withConfigWatch(fn () => null, 'aaa');

        $this->assertFalse($consumer->configChanged());
    }

    public function test_it_stops_without_handing_buffered_events_to_the_old_handlers(): void
    {
        $handler = new class () implements BatchHandler {
            public int $batches = 0;

            public function handle(Batch $batch): void
            {
                $this->batches++;
            }
        };
        $consumer = $this->consumer([$handler], batchSize: 10)->withConfigWatch(fn () => 'new', 'old');
        $consumer->begin();

        $consumer->handleMessage($this->recordMessage(1));
        $consumer->handleMessage($this->recordMessage(2));
        $this->assertSame(2, $consumer->buffer()->pending());

        (fn () => $this->stopIfConfigChanged())->call($consumer);

        $this->assertSame('the deployed config changed', $consumer->stopReason());
        $this->assertSame(0, $consumer->buffer()->pending(), 'Buffered events are dropped unacknowledged, so Tap redelivers them.');
        $this->assertSame(0, $handler->batches);
    }

    public function test_the_signature_changes_when_a_config_file_does(): void
    {
        $file = config_path('atp-appview-signature-test.php');
        file_put_contents($file, '<?php return [];');

        try {
            $before = DeployedConfig::signature();
            $this->assertSame($before, DeployedConfig::signature(), 'Stable while nothing changes.');

            file_put_contents($file, "<?php return ['changed' => true];");
            $this->assertNotSame($before, DeployedConfig::signature());
        } finally {
            unlink($file);
        }
    }

    protected function consumer(array $handlers = [], int $batchSize = 1): TapConsumer
    {
        return new class ($handlers, 'ws://localhost:2480/channel', batchSize: $batchSize) extends TapConsumer {
            public function begin(): void
            {
                parent::begin();
            }
        };
    }

    protected function recordMessage(int $id): string
    {
        return json_encode(['id' => $id, 'type' => 'record', 'record' => [
            'live' => true, 'rev' => '3k', 'did' => 'did:plc:alice', 'collection' => 'app.bsky.feed.post',
            'rkey' => "r{$id}", 'action' => 'create', 'cid' => 'bafy', 'record' => ['text' => 'hi'],
        ]]);
    }
}
