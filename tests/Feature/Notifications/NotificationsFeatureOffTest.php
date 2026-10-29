<?php

namespace SocialDept\AtpAppview\Tests\Feature\Notifications;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Notifications\NotificationIndexer;
use SocialDept\AtpAppview\Notifications\NotificationPreferences;
use SocialDept\AtpAppview\Notifications\Notifications;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpAppview\Xrpc\XrpcRegistry;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * With notifications left out, reasons configured for them do nothing: no
 * tables, no methods, no command, and the APIs refuse.
 */
class NotificationsFeatureOffTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.features', [Features::moderation(), Features::graph()]);
        $app['config']->set('atp-appview.notifications.reasons', ['com.example.like' => ['subject.uri' => 'like']]);
        $app['config']->set('atp-appview.notifications.xrpc.list', 'com.example.listNotifications');
        // As a config published before notifications were a feature lists it.
        $app['config']->set('atp-appview.ingest.handlers', [\SocialDept\AtpAppview\Index\RecordIndexer::class, NotificationIndexer::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
    }

    public function test_notifications_left_out_keep_nothing_serve_nothing_and_refuse(): void
    {
        $this->assertFalse(Schema::hasTable('atp_notifications'));
        $this->assertFalse(Schema::hasTable('atp_notification_preferences'));
        $this->assertArrayNotHasKey('appview:notifications:build', Artisan::all());
        $this->assertNull(app(XrpcRegistry::class)->find('com.example.listNotifications'));
        $this->assertNotContains(NotificationIndexer::class, array_map('get_class', Pipeline::handlers(app())));

        // A like still indexes, with nothing kept for it.
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(1, 'did:plc:bob', 'com.example.like', 'l1', RecordAction::Create, 'bafy-l1', '3l2aaaaaaaaaa', ['subject' => ['uri' => 'at://did:plc:alice/com.example.thing/a']], true),
        ]));
        $this->assertSame(1, \SocialDept\AtpAppview\Models\Record::query()->count());

        foreach ([fn () => app(Notifications::class)->unreadCount('did:plc:alice'), fn () => app(NotificationPreferences::class)->for('did:plc:alice', ['like'])] as $call) {
            try {
                $call();
                $this->fail('Expected the API to refuse.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('Features::notifications()', $e->getMessage());
            }
        }
    }
}
