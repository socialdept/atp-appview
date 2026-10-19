<?php

namespace SocialDept\AtpAppview\Tests\Feature\Labels;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Labels\SubjectSync;
use SocialDept\AtpAppview\Labels\Tack;
use SocialDept\AtpAppview\Models\Label;
use SocialDept\AtpAppview\Models\Labeler;

class TackTest extends TestCase
{
    protected const AUTH = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'tack-secret'];

    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.labels.tack.url', 'http://tack.test');
        $app['config']->set('atp-appview.labels.tack.password', 'tack-secret');
        $app['config']->set('atp-appview.ingest.admin_url', 'http://tap.test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    protected function labelEvent(int $id, string $uri, string $val = 'spam'): array
    {
        return ['id' => $id, 'type' => 'label', 'verified' => true, 'label' => ['src' => 'did:plc:mod', 'uri' => $uri, 'val' => $val, 'neg' => false, 'cts' => '2026-10-19T12:00:00.000Z']];
    }

    public function test_the_webhook_takes_tacks_events_signed_with_its_password(): void
    {
        $this->postJson('/atp-appview/tack', $this->labelEvent(1, 'did:plc:alice'))->assertUnauthorized();

        $this->call('POST', '/atp-appview/tack', [], [], [], [...self::AUTH, 'CONTENT_TYPE' => 'application/json'], json_encode($this->labelEvent(1, 'did:plc:alice')))->assertOk()->assertJson(['handled' => 1]);

        $this->call('POST', '/atp-appview/tack', [], [], [], [...self::AUTH, 'CONTENT_TYPE' => 'application/json'], json_encode(['events' => [
            $this->labelEvent(2, 'at://did:plc:alice/app.example.post/1', 'porn'),
            ['id' => 3, 'type' => 'labeler', 'labeler' => ['did' => 'did:plc:mod', 'endpoint' => 'https://mod.example', 'declaration' => ['policies' => ['labelValues' => ['spam', 'porn']]]]],
        ]]))->assertOk()->assertJson(['handled' => 2]);

        $this->assertSame(['porn', 'spam'], Label::query()->orderBy('val')->pluck('val')->all());
        $this->assertSame('https://mod.example', Labeler::query()->sole()->endpoint);
    }

    public function test_labels_are_kept_when_a_published_config_lists_only_older_handlers(): void
    {
        config(['atp-appview.ingest.handlers' => [\SocialDept\AtpAppview\Index\RecordIndexer::class]]);

        $this->call('POST', '/atp-appview/tack', [], [], [], [...self::AUTH, 'CONTENT_TYPE' => 'application/json'], json_encode($this->labelEvent(1, 'did:plc:alice')))->assertOk();

        $this->assertSame(1, Label::query()->count());
    }

    public function test_the_webhook_refuses_everything_without_a_password(): void
    {
        config(['atp-appview.labels.tack.password' => null]);

        $this->postJson('/atp-appview/tack', $this->labelEvent(1, 'did:plc:alice'))->assertForbidden();
    }

    public function test_a_label_about_a_blocked_account_does_not_send_it_back_to_tap(): void
    {
        Http::fake();
        DB::table('atp_blocks')->insert(['kind' => 'did', 'subject' => 'did:plc:spam', 'collection' => '*', 'created_at' => now(), 'updated_at' => now()]);
        app(Blocklist::class)->forget();

        $this->call('POST', '/atp-appview/tack', [], [], [], [...self::AUTH, 'CONTENT_TYPE' => 'application/json'], json_encode($this->labelEvent(1, 'did:plc:spam')))->assertOk();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'tap.test'));
        $this->assertSame(1, Label::query()->count());
    }

    public function test_sync_registers_new_accounts_with_their_records_and_sends_late_ones(): void
    {
        Http::fake(['tack.test/*' => Http::response(['added' => 1, 'urisQueued' => 1])]);
        DB::table('atp_identities')->insert(['did' => 'did:plc:alice', 'handle' => 'alice.test', 'active' => true, 'updated_at' => now()]);
        $this->record('did:plc:alice', 'old', '2026-01-01 00:00:00', '2026-01-01 00:00:00');

        $this->assertSame(['accounts' => 1, 'uris' => 0, 'expired' => 0], app(SubjectSync::class)->run());

        Http::assertSent(fn (Request $request) => $request->url() === 'http://tack.test/subjects/add'
            && $request['dids'] === ['did:plc:alice']
            && $request['uris'] === ['at://did:plc:alice/app.example.post/old']
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('admin:tack-secret')));

        // Indexed after registering: one made before (a late backfill) is
        // sent, one made since is not.
        $synced = DB::table('atp_identities')->value('labels_synced_at');
        $this->record('did:plc:alice', 'late', now()->addMinute(), '2025-06-01 00:00:00');
        $this->record('did:plc:alice', 'new', now()->addMinute(), now()->addMinute());
        Cache::forever('atp-appview:labels:sync:indexed', $synced);

        $this->travel(2)->minutes();
        $this->assertSame(['accounts' => 0, 'uris' => 1, 'expired' => 0], app(SubjectSync::class)->run());

        Http::assertSent(fn (Request $request) => $request->url() === 'http://tack.test/subjects/uris'
            && $request['uris'] === ['at://did:plc:alice/app.example.post/late']);
    }

    public function test_the_websocket_is_at_channel_on_tacks_address(): void
    {
        $this->assertSame('ws://tack.test/channel', Tack::channelUrl());

        config(['atp-appview.labels.tack.url' => 'https://tack.example/']);
        $this->assertSame('wss://tack.example/channel', Tack::channelUrl());
    }

    protected function record(string $did, string $rkey, mixed $indexedAt, mixed $sortAt): void
    {
        DB::table('atp_records')->insert([
            'uri' => "at://{$did}/app.example.post/{$rkey}", 'did' => $did, 'collection' => 'app.example.post', 'rkey' => $rkey,
            'cid' => 'bafy', 'record' => '{}', 'rev' => '3l2aaaaaaaaaa', 'indexed_at' => $indexedAt, 'sort_at' => $sortAt,
        ]);
    }
}
