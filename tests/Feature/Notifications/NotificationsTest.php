<?php

namespace SocialDept\AtpAppview\Tests\Feature\Notifications;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Notifications\Events\NotificationCreated;
use SocialDept\AtpAppview\Notifications\Notifications;
use SocialDept\AtpAppview\Tests\Fixtures\Views\ThingView;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class NotificationsTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const THING = 'com.example.thing';
    protected const LIKE = 'com.example.like';
    protected const FOLLOW = 'com.example.follow';

    protected int $id = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.middleware', ['web']);
        $app['config']->set('atp-appview.views', [ThingView::class]);
        $app['config']->set('atp-appview.notifications.reasons', [
            self::LIKE => ['subject.uri' => 'like'],
            self::THING => ['reply.parent.uri' => 'reply', 'facets.*.features.*.did' => 'mention'],
            self::FOLLOW => ['subject' => 'follow'],
        ]);
        $app['config']->set('atp-appview.notifications.separate', ['reply', 'mention']);
        $app['config']->set('atp-appview.notifications.xrpc', [
            'list' => 'com.example.listNotifications',
            'unread_count' => 'com.example.getUnreadCount',
            'update_seen' => 'com.example.updateSeen',
        ]);
        $app['config']->set('auth.providers.users.model', NotificationsUser::class);
        $app['config']->set('auth.guards.atp', ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('did');
            $table->rememberToken();
        });

        foreach ([self::ALICE, self::BOB, self::CAROL] as $did) {
            DB::table('atp_identities')->insert(['did' => $did, 'handle' => substr($did, 8).'.test', 'pds' => 'https://pds.example', 'active' => true, 'updated_at' => now()]);
        }
    }

    protected function index(string $did, string $collection, string $rkey, ?array $record, string $rev = '3l2aaaaaaaaaa'): string
    {
        $action = $record === null ? RecordAction::Delete : RecordAction::Create;

        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, $collection, $rkey, $action, $record === null ? null : "bafy-{$rkey}-{$rev}", $rev, $record, true),
        ]));

        return "at://{$did}/{$collection}/{$rkey}";
    }

    protected function thing(string $did, string $rkey, array $extra = []): string
    {
        return $this->index($did, self::THING, $rkey, ['text' => $rkey, 'createdAt' => '2026-10-01T00:00:00Z', ...$extra]);
    }

    protected function like(string $did, string $rkey, string $subject): string
    {
        return $this->index($did, self::LIKE, $rkey, ['subject' => ['uri' => $subject, 'cid' => 'bafy'], 'createdAt' => '2026-10-01T00:00:00Z']);
    }

    protected function notifications(): Notifications
    {
        return app(Notifications::class);
    }

    public function test_a_like_notifies_the_author_of_what_it_likes_and_never_the_liker_of_their_own(): void
    {
        Event::fake([NotificationCreated::class]);
        $post = $this->thing(self::ALICE, 'post');

        $like = $this->like(self::BOB, 'l1', $post);
        $this->like(self::ALICE, 'l2', $post);

        $rows = DB::table('atp_notifications')->get();
        $this->assertCount(1, $rows);
        $this->assertSame([self::ALICE, 'like', $post, self::BOB, $like], [$rows[0]->recipient_did, $rows[0]->reason, $rows[0]->subject, $rows[0]->actor_did, $rows[0]->source_uri]);

        Event::assertDispatchedTimes(NotificationCreated::class, 1);
        Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $event) => $event->recipient === self::ALICE && $event->uri === $like);
    }

    public function test_a_deleted_record_takes_its_notification_with_it_and_a_replay_changes_nothing(): void
    {
        $post = $this->thing(self::ALICE, 'post');
        $this->like(self::BOB, 'l1', $post);
        $id = DB::table('atp_notifications')->value('id');

        // The same record delivered again keeps its notification as it was.
        $this->like(self::BOB, 'l1', $post);
        $this->assertSame([$id], DB::table('atp_notifications')->pluck('id')->all());

        $this->index(self::BOB, self::LIKE, 'l1', null, '3l2bbbbbbbbbb');
        $this->assertSame(0, DB::table('atp_notifications')->count());

        // An older create replayed after the delete brings nothing back.
        $this->like(self::BOB, 'l1', $post);
        $this->assertSame(0, DB::table('atp_notifications')->count());
    }

    public function test_likes_on_one_record_group_together_and_replies_stay_apart(): void
    {
        $post = $this->thing(self::ALICE, 'post');
        $other = $this->thing(self::ALICE, 'other');
        $this->like(self::BOB, 'l1', $post);
        $this->like(self::CAROL, 'l2', $post);
        $this->like(self::BOB, 'l3', $other);
        $this->thing(self::BOB, 'r1', ['reply' => ['parent' => ['uri' => $post], 'root' => ['uri' => $post]]]);
        $this->thing(self::CAROL, 'r2', ['reply' => ['parent' => ['uri' => $post], 'root' => ['uri' => $post]]]);

        $page = $this->notifications()->groups(self::ALICE);
        $groups = collect($page['notifications']);

        $this->assertSame(['reply', 'reply', 'like', 'like'], $groups->pluck('reason')->all());
        $likes = $groups->where('reason', 'like')->firstWhere('subject', $post);
        $this->assertSame(2, $likes['count']);
        $this->assertSame([self::CAROL, self::BOB], array_column(array_column($likes['latest'], 'actor'), 'did'));
        $this->assertSame($post, $likes['subjectView']['uri'], 'The subject is hydrated with its view.');
        $this->assertSame(1, $groups->where('subject', $other)->first()['count']);

        // Each reply is its own group, carrying the reply's view.
        $replies = $groups->where('reason', 'reply')->values();
        $this->assertSame([1, 1], $replies->pluck('count')->all());
        $this->assertSame('at://did:plc:carol/com.example.thing/r2', $replies[0]['latest'][0]['view']['uri']);

        // One at a time, every notification carries the group it belongs to.
        $flat = collect($this->notifications()->list(self::ALICE)['notifications']);
        $this->assertCount(5, $flat);
        $this->assertSame($likes['groupKey'], $flat->firstWhere('uri', 'at://did:plc:carol/com.example.like/l2')['groupKey']);
    }

    public function test_follows_and_mentions_notify_the_account_named(): void
    {
        $this->index(self::BOB, self::FOLLOW, 'f1', ['subject' => self::ALICE, 'createdAt' => '2026-10-01T00:00:00Z']);
        $this->index(self::CAROL, self::FOLLOW, 'f2', ['subject' => self::ALICE, 'createdAt' => '2026-10-01T00:00:00Z']);
        $this->thing(self::BOB, 'hi', ['facets' => [['features' => [['$type' => 'mention', 'did' => self::ALICE], ['$type' => 'mention', 'did' => self::CAROL]]]]]);

        $groups = collect($this->notifications()->groups(self::ALICE)['notifications']);

        $this->assertSame(['mention', 'follow'], $groups->pluck('reason')->all());
        $this->assertSame(2, $groups->firstWhere('reason', 'follow')['count']);
        $this->assertSame(self::ALICE, $groups->firstWhere('reason', 'follow')['subject']);
        $this->assertSame(1, $this->notifications()->unreadCount(self::CAROL));
    }

    public function test_pages_of_groups_follow_the_cursor(): void
    {
        foreach (['a', 'b', 'c'] as $rkey) {
            $this->like(self::BOB, "l{$rkey}", $this->thing(self::ALICE, $rkey));
        }

        $first = $this->notifications()->groups(self::ALICE, 2);
        $second = $this->notifications()->groups(self::ALICE, 2, $first['cursor']);

        $this->assertCount(2, $first['notifications']);
        $this->assertNotNull($first['cursor']);
        $this->assertSame(['at://did:plc:alice/com.example.thing/a'], array_column($second['notifications'], 'subject'));
        $this->assertNull($second['cursor']);
    }

    public function test_seen_marks_what_came_before_it_read(): void
    {
        $post = $this->thing(self::ALICE, 'post');
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->like(self::BOB, 'l1', $post);
        $this->assertSame(1, $this->notifications()->unreadCount(self::ALICE));

        $this->notifications()->markSeen(self::ALICE);
        Carbon::setTestNow('2026-10-20 11:00:00');
        $this->like(self::CAROL, 'l2', $post);

        $this->assertSame(1, $this->notifications()->unreadCount(self::ALICE));
        $group = $this->notifications()->groups(self::ALICE)['notifications'][0];
        $this->assertSame([2, 1, false], [$group['count'], $group['unreadCount'], $group['isRead']]);

        // Seen never moves back.
        $this->notifications()->markSeen(self::ALICE, Carbon::parse('2026-10-01'));
        $this->assertSame('2026-10-20 10:00:00', $this->notifications()->seenAt(self::ALICE)->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_moderated_or_hidden_actors_are_left_out_until_the_action_is_lifted(): void
    {
        Event::fake([NotificationCreated::class]);
        $post = $this->thing(self::ALICE, 'post');
        $like = $this->like(self::BOB, 'l1', $post);

        DB::table('atp_records')->where('uri', $like)->update(['moderated_at' => now()]);
        $this->assertSame(0, $this->notifications()->unreadCount(self::ALICE));
        $this->assertSame([], $this->notifications()->groups(self::ALICE)['notifications']);

        DB::table('atp_records')->where('uri', $like)->update(['moderated_at' => null, 'limited_at' => now()]);
        $this->assertSame(0, $this->notifications()->unreadCount(self::ALICE), 'Accounts needing review stay out.');

        DB::table('atp_records')->where('uri', $like)->update(['limited_at' => null]);
        $this->assertSame(1, $this->notifications()->unreadCount(self::ALICE));
    }

    public function test_building_adds_notifications_for_records_indexed_before_their_reasons_without_announcing_them(): void
    {
        $reasons = config('atp-appview.notifications.reasons');
        config()->set('atp-appview.notifications.reasons', []);
        $post = $this->thing(self::ALICE, 'post');
        $this->like(self::BOB, 'l1', $post);
        $this->assertSame(0, DB::table('atp_notifications')->count());

        Event::fake([NotificationCreated::class]);
        config()->set('atp-appview.notifications.reasons', $reasons);
        $this->artisan('appview:notifications:build')->assertSuccessful();

        $this->assertSame(1, DB::table('atp_notifications')->count());
        $this->assertSame(
            DB::table('atp_records')->where('uri', 'at://did:plc:bob/com.example.like/l1')->value('indexed_at'),
            DB::table('atp_notifications')->value('indexed_at'),
            'A built notification takes its record\'s time.',
        );
        Event::assertNotDispatched(NotificationCreated::class);

        $this->artisan('appview:notifications:build')->assertSuccessful();
        $this->assertSame(1, DB::table('atp_notifications')->count(), 'Building twice adds nothing.');
    }

    public function test_the_xrpc_methods_serve_the_signed_in_viewer(): void
    {
        $post = $this->thing(self::ALICE, 'post');
        $this->like(self::BOB, 'l1', $post);
        $alice = NotificationsUser::create(['did' => self::ALICE]);

        $this->getJson('/xrpc/com.example.listNotifications')->assertStatus(401);

        $this->actingAs($alice, 'web')->getJson('/xrpc/com.example.getUnreadCount')->assertOk()->assertExactJson(['count' => 1]);

        $this->actingAs($alice, 'web')->getJson('/xrpc/com.example.listNotifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.reason', 'like')
            ->assertJsonPath('notifications.0.count', 1)
            ->assertJsonPath('notifications.0.latest.0.actor.did', self::BOB);

        $this->actingAs($alice, 'web')->getJson('/xrpc/com.example.listNotifications?grouped=false')
            ->assertOk()
            ->assertJsonPath('notifications.0.uri', 'at://did:plc:bob/com.example.like/l1')
            ->assertJsonPath('notifications.0.isRead', false);

        $this->actingAs($alice, 'web')->postJson('/xrpc/com.example.updateSeen', [])->assertOk()->assertJsonStructure(['seenAt']);
        $this->actingAs($alice, 'web')->getJson('/xrpc/com.example.getUnreadCount')->assertExactJson(['count' => 0]);
    }
}

class NotificationsUser extends Authenticatable implements HasAtpSession
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getAtpDid(): ?string
    {
        return $this->did;
    }
}
