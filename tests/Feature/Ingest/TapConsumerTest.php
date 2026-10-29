<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Ingest\TapConsumer;
use SocialDept\AtpAppview\Tests\TestCase;

class TapConsumerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    public function test_a_full_batch_is_handed_to_the_handlers(): void
    {
        $handler = new RecordingHandler();
        $consumer = new TapConsumer([$handler], 'ws://localhost:2480/channel', batchSize: 2);

        $consumer->handleMessage($this->recordMessage(1));
        $this->assertSame([], $handler->batches);

        $consumer->handleMessage($this->recordMessage(2));

        $this->assertCount(1, $handler->batches);
        $this->assertSame(2, $handler->batches[0]->count());
        $this->assertSame(0, $consumer->buffer()->pending());
    }

    public function test_a_malformed_event_still_counts_toward_the_batch_so_it_gets_acknowledged(): void
    {
        $handler = new RecordingHandler();
        $consumer = new TapConsumer([$handler], 'ws://localhost:2480/channel', batchSize: 10);

        $consumer->handleMessage(json_encode(['id' => 1, 'type' => 'record', 'record' => ['did' => 'did:plc:a']]));
        $consumer->handleMessage(json_encode(['id' => 2, 'type' => 'unknown']));

        $this->assertSame(2, $consumer->buffer()->pending());
    }

    public function test_a_message_without_an_id_is_ignored(): void
    {
        $consumer = new TapConsumer([new RecordingHandler()], 'ws://localhost:2480/channel');

        $consumer->handleMessage('not json');
        $consumer->handleMessage(json_encode(['type' => 'record']));

        $this->assertSame(0, $consumer->buffer()->pending());
    }

    public function test_the_container_builds_the_consumer_from_config(): void
    {
        config()->set('atp-appview.ingest.handlers', [RecordingHandler::class]);
        config()->set('atp-appview.ingest.batch_size', 1);

        $consumer = $this->app->make(TapConsumer::class);
        $consumer->handleMessage($this->recordMessage(1));

        $this->assertSame(0, $consumer->buffer()->pending());
    }

    public function test_the_consume_command_is_registered(): void
    {
        $this->assertArrayHasKey('appview:consume', \Illuminate\Support\Facades\Artisan::all());
    }

    protected function recordMessage(int $id): string
    {
        return json_encode(['id' => $id, 'type' => 'record', 'record' => [
            'live' => true, 'rev' => '3k', 'did' => 'did:plc:alice', 'collection' => 'app.bsky.feed.post',
            'rkey' => "r{$id}", 'action' => 'create', 'cid' => 'bafy', 'record' => ['text' => 'hi'],
        ]]);
    }
}

class RecordingHandler implements BatchHandler
{
    /** @var array<int, Batch> */
    public array $batches = [];

    public function handle(Batch $batch): void
    {
        $this->batches[] = $batch;
    }
}
