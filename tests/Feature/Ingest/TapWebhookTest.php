<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Backfill\BackfillTracker;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Models\Backfill;
use SocialDept\AtpAppview\Models\BackfillRepo;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;

class TapWebhookTest extends TestCase
{
    protected const PATH = '/atp-appview/tap';

    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.ingest.password', 'secret');
        $app['config']->set('atp-appview.ingest.handlers', [RecordIndexer::class, BackfillTracker::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    protected function deliver(array $payload, ?string $password = 'secret'): \Illuminate\Testing\TestResponse
    {
        $headers = $password === null ? [] : ['Authorization' => 'Basic '.base64_encode("admin:{$password}")];

        return $this->postJson(self::PATH, $payload, $headers);
    }

    protected function record(int $id, string $rkey, bool $live = true, string $did = 'did:plc:alice'): array
    {
        return ['id' => $id, 'type' => 'record', 'record' => [
            'live' => $live, 'rev' => '3l2aaaaaaaaaa', 'did' => $did, 'collection' => 'app.example.post',
            'rkey' => $rkey, 'action' => 'create', 'cid' => "bafy-{$rkey}", 'record' => ['text' => $rkey],
        ]];
    }

    public function test_the_webhook_is_on_by_default(): void
    {
        $this->assertTrue(Route::has('atp-appview.tap-webhook'));
    }

    #[DefineEnvironment('turnTheWebhookOff')]
    public function test_the_webhook_can_be_turned_off_for_appview_consume(): void
    {
        $this->assertFalse(Route::has('atp-appview.tap-webhook'));
        $this->postJson(self::PATH, ['events' => []])->assertNotFound();
    }

    protected function turnTheWebhookOff($app): void
    {
        $app['config']->set('atp-appview.ingest.webhook.enabled', false);
    }

    public function test_a_batch_is_indexed_and_reported(): void
    {
        $this->deliver(['events' => [
            $this->record(1, 'a'),
            $this->record(2, 'b'),
            ['id' => 3, 'type' => 'identity', 'identity' => ['did' => 'did:plc:alice', 'handle' => 'alice.test', 'is_active' => true, 'status' => 'active']],
        ]])->assertOk()->assertJson(['handled' => 3]);

        $this->assertSame(2, Record::query()->count());
        $this->assertSame('alice.test', Identity::query()->find('did:plc:alice')->handle);
    }

    public function test_a_single_event_from_a_tap_without_batching_is_indexed(): void
    {
        $this->deliver($this->record(1, 'a'))->assertOk()->assertJson(['handled' => 1]);

        $this->assertSame(['text' => 'a'], Record::query()->sole()->record);
    }

    public function test_a_malformed_event_is_skipped_and_the_rest_are_indexed(): void
    {
        $this->deliver(['events' => [
            $this->record(1, 'a'),
            ['id' => 2, 'type' => 'record', 'record' => ['did' => 'did:plc:alice']],
            $this->record(3, 'c'),
        ]])->assertOk()->assertJson(['handled' => 2]);

        $this->assertSame(['a', 'c'], Record::query()->orderBy('rkey')->pluck('rkey')->all());
    }

    public function test_a_batch_the_handlers_cannot_take_fails_so_tap_retries_it(): void
    {
        config([
            'atp-appview.ingest.handlers' => [FailingHandler::class],
            'atp-appview.ingest.max_attempts' => 1,
        ]);

        $this->deliver(['events' => [$this->record(1, 'a')]])->assertStatus(503);
    }

    public function test_backfilled_records_arriving_by_webhook_are_tracked(): void
    {
        $backfill = Backfill::query()->create(['collection' => 'app.example.post', 'source' => 'test', 'started_at' => now()]);
        BackfillRepo::query()->insert(['backfill_id' => $backfill->id, 'did' => 'did:plc:alice', 'method' => 'resync']);

        $this->deliver(['events' => [$this->record(1, 'a', live: false), $this->record(2, 'b', live: false)]])->assertOk();

        $repo = BackfillRepo::query()->sole();
        $this->assertNotNull($repo->delivered_at);
        $this->assertSame(2, $repo->records);
    }

    public function test_requests_without_tap_s_password_are_refused(): void
    {
        $this->deliver(['events' => [$this->record(1, 'a')]], password: null)->assertUnauthorized();
        $this->deliver(['events' => [$this->record(1, 'a')]], password: 'wrong')->assertUnauthorized();

        $this->assertSame(0, Record::query()->count());
    }

    public function test_it_refuses_everything_until_a_password_is_set(): void
    {
        config(['atp-appview.ingest.password' => null]);

        $this->deliver(['events' => [$this->record(1, 'a')]], password: '')->assertForbidden();
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $this->deliver([])->assertStatus(400);
    }
}

class FailingHandler implements BatchHandler
{
    public function handle(Batch $batch): void
    {
        throw new RuntimeException('database down');
    }
}
