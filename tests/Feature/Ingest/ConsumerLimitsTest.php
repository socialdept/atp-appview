<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Ingest\TapConsumer;
use SocialDept\AtpAppview\Tests\TestCase;

class ConsumerLimitsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    public function test_it_stops_after_the_batch_limit(): void
    {
        $consumer = $this->consumer()->withLimits(maxBatches: 2);
        $consumer->begin();

        $consumer->handleMessage($this->recordMessage(1));
        $this->assertNull($consumer->stopReason());

        $consumer->handleMessage($this->recordMessage(2));
        $this->assertSame('handled 2 batches', $consumer->stopReason());
    }

    public function test_it_stops_at_the_memory_limit(): void
    {
        $consumer = $this->consumer()->withLimits(memory: 1);
        $consumer->begin();

        $this->assertStringStartsWith('memory reached', (string) $consumer->limitReached());
    }

    public function test_it_stops_after_the_time_limit(): void
    {
        $consumer = $this->consumer()->withLimits(maxTime: 60);
        $consumer->begin();

        $consumer->clock += 59;
        $this->assertNull($consumer->limitReached());

        $consumer->clock += 1;
        $this->assertSame('ran for 60 seconds', $consumer->limitReached());
    }

    public function test_it_stops_when_a_restart_is_broadcast_after_it_started(): void
    {
        $this->artisan('appview:restart')->assertSuccessful();

        $consumer = $this->consumer()->withRestartSignal(Cache::store());
        $consumer->begin();

        // A signal from before this run started does not count.
        $this->assertNull($consumer->limitReached());

        usleep(1000);
        $this->artisan('appview:restart')->assertSuccessful();

        $this->assertSame('restart signal received', $consumer->limitReached());
    }

    public function test_no_limits_means_it_never_stops_itself(): void
    {
        $consumer = $this->consumer();
        $consumer->begin();
        $consumer->clock += 86400;

        foreach (range(1, 5) as $id) {
            $consumer->handleMessage($this->recordMessage($id));
        }

        $this->assertNull($consumer->stopReason());
    }

    public function test_the_query_log_is_turned_off_for_every_connection(): void
    {
        DB::connection()->enableQueryLog();
        DB::select('select 1');

        $consumer = $this->consumer();
        $consumer->begin();

        $this->assertFalse(DB::connection()->logging());
        $this->assertSame([], DB::connection()->getQueryLog());

        DB::connection()->enableQueryLog();
        $consumer->handleMessage($this->recordMessage(1));

        $this->assertFalse(DB::connection()->logging());
    }

    public function test_the_consume_command_takes_queue_work_style_limits(): void
    {
        $definition = Artisan::all()['appview:consume']->getDefinition();

        $this->assertSame('0', $definition->getOption('max-time')->getDefault());
        $this->assertSame('0', $definition->getOption('max-batches')->getDefault());
        $this->assertSame('128', $definition->getOption('memory')->getDefault());
        $this->assertArrayHasKey('appview:restart', Artisan::all());
    }

    protected function consumer(): TapConsumer
    {
        return new class ([], 'ws://localhost:2480/channel', batchSize: 1) extends TapConsumer {
            public int $clock = 1_000_000;

            public function begin(): void
            {
                parent::begin();
            }

            protected function now(): int
            {
                return $this->clock;
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
