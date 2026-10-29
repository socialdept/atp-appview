<?php

namespace SocialDept\AtpAppview\Tests\Feature\Realtime;

use Illuminate\Support\Facades\Event;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Auth\FrontendAuth;
use SocialDept\AtpAppview\Index\Events\RecordIndexed;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Realtime\Ping;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Without Features::realtime(), records still announce themselves, but
 * nothing is broadcast and no authorize method is served.
 */
class RealtimeFeatureOffTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.ingest.collections', ['com.example.post']);
        $app['config']->set('atp-appview.features', []);
        $app['config']->set('atp-appview.realtime', ['collections' => ['com.example.post'], 'window' => 0, 'xrpc' => ['authorize' => 'com.example.realtime.authorize']]);
    }

    public function test_records_announce_themselves_but_nothing_is_broadcast(): void
    {
        $this->artisan('migrate')->run();
        Event::fake([Ping::class]);
        $indexed = [];
        Event::listen(RecordIndexed::class, function (RecordIndexed $event) use (&$indexed) {
            $indexed[] = $event->uri;
        });

        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(1, 'did:plc:alice', 'com.example.post', 'p1', RecordAction::Create, 'bafy-p1', '3l2aaaaaaaaaa', ['text' => 'hi', 'createdAt' => '2026-10-21T00:00:00Z'], true),
        ]));

        $this->assertSame(['at://did:plc:alice/com.example.post/p1'], $indexed);
        Event::assertNotDispatched(Ping::class);
        $this->postJson('/xrpc/com.example.realtime.authorize', [])->assertStatus(501);
        $this->assertNull(FrontendAuth::browserConfig()['realtime']);
    }
}
