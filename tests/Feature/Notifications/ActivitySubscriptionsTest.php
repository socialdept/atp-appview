<?php

namespace SocialDept\AtpAppview\Tests\Feature\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Notifications\ActivitySubscriptions;
use SocialDept\AtpAppview\Notifications\Notifications;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Asking to hear when another account writes: kinds of activity named in
 * config, subscriptions kept privately, and a notification for each
 * matching record a subscribed account writes.
 */
class ActivitySubscriptionsTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const THING = 'com.example.thing';

    protected int $id = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.notifications.activity', [
            'post' => ['collection' => self::THING, 'without' => 'reply', 'reason' => 'subscribed-post'],
            'reply' => ['collection' => self::THING, 'with' => 'reply', 'reason' => 'subscribed-reply'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
    }

    protected function index(string $did, string $rkey, array $record, bool $live = true): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, self::THING, $rkey, RecordAction::Create, "bafy-{$rkey}", '3l2aaaaaaaaaa', $record + ['createdAt' => '2026-10-01T00:00:00Z'], $live),
        ]));

        return "at://{$did}/".self::THING."/{$rkey}";
    }

    protected function subscriptions(): ActivitySubscriptions
    {
        return app(ActivitySubscriptions::class);
    }

    /**
     * @return array<int, array{reason: string, source: string}>
     */
    protected function notified(string $did): array
    {
        return DB::table('atp_notifications')->where('recipient_did', $did)->orderBy('id')
            ->get(['reason', 'source_uri'])
            ->map(fn ($row) => ['reason' => $row->reason, 'source' => $row->source_uri])
            ->all();
    }

    public function test_subscribers_hear_about_the_kinds_they_chose(): void
    {
        $this->subscriptions()->set(self::ALICE, self::BOB, ['post']);
        $this->subscriptions()->set(self::CAROL, self::BOB, ['post', 'reply']);

        $post = $this->index(self::BOB, 'p1', ['text' => 'hello']);
        $reply = $this->index(self::BOB, 'r1', ['text' => 'hi back', 'reply' => ['parent' => ['uri' => $post]]]);
        $this->index(self::CAROL, 'c1', ['text' => 'not subscribed to']);

        $this->assertSame([['reason' => 'subscribed-post', 'source' => $post]], $this->notified(self::ALICE));
        $this->assertSame([
            ['reason' => 'subscribed-post', 'source' => $post],
            ['reason' => 'subscribed-reply', 'source' => $reply],
        ], $this->notified(self::CAROL));
        $this->assertSame([], $this->notified(self::BOB));
    }

    public function test_each_record_notifies_on_its_own_and_only_when_live(): void
    {
        $this->subscriptions()->set(self::ALICE, self::BOB, ['post']);

        $this->index(self::BOB, 'p1', ['text' => 'one']);
        $this->index(self::BOB, 'p2', ['text' => 'two']);
        $this->index(self::BOB, 'old', ['text' => 'backfilled'], live: false);

        $groups = app(Notifications::class)->groups(self::ALICE)['notifications'];
        $this->assertCount(2, $groups, 'One group per record, and none for a backfill.');
    }

    public function test_choices_are_replaced_and_cleared(): void
    {
        $this->subscriptions()->set(self::ALICE, self::BOB, ['post', 'reply']);
        $this->assertSame(['post', 'reply'], $this->subscriptions()->for(self::ALICE, self::BOB));

        $this->subscriptions()->set(self::ALICE, self::BOB, ['reply']);
        $this->assertSame(['reply'], $this->subscriptions()->for(self::ALICE, self::BOB));
        $this->assertSame([self::BOB => ['reply']], $this->subscriptions()->subscriptions(self::ALICE));

        $this->subscriptions()->set(self::ALICE, self::BOB, []);
        $this->assertSame([], $this->subscriptions()->for(self::ALICE, self::BOB));
        $this->assertSame([], $this->subscriptions()->subscriptions(self::ALICE));
    }

    public function test_a_kind_removed_from_config_stops_applying(): void
    {
        $this->subscriptions()->set(self::ALICE, self::BOB, ['post', 'reply']);
        config()->set('atp-appview.notifications.activity', ['reply' => ['collection' => self::THING, 'with' => 'reply', 'reason' => 'subscribed-reply']]);

        $this->assertSame(['reply'], $this->subscriptions()->for(self::ALICE, self::BOB));
        $this->index(self::BOB, 'p1', ['text' => 'hello']);
        $this->assertSame([], $this->notified(self::ALICE));
    }

    public function test_unknown_kinds_and_self_subscriptions_are_refused(): void
    {
        try {
            $this->subscriptions()->set(self::ALICE, self::BOB, ['likes']);
            $this->fail('An unknown kind was accepted.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $this->subscriptions()->set(self::ALICE, self::ALICE, ['post']);
    }

    public function test_a_muted_account_is_left_out_when_read(): void
    {
        $this->subscriptions()->set(self::ALICE, self::BOB, ['post']);
        $this->index(self::BOB, 'p1', ['text' => 'hello']);
        $this->assertSame(1, app(Notifications::class)->unreadCount(self::ALICE));

        app(Graph::class)->mute(self::ALICE, self::BOB);
        $this->assertSame(0, app(Notifications::class)->unreadCount(self::ALICE));
    }
}
