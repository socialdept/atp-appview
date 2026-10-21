<?php

namespace SocialDept\AtpAppview\Tests\Feature\Realtime;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Auth\FrontendAuth;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Index\Events\RecordIndexed;
use SocialDept\AtpAppview\Index\Events\RecordRemoved;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Notifications\Events\NotificationCreated;
use SocialDept\AtpAppview\Realtime\FlushPings;
use SocialDept\AtpAppview\Realtime\Ping;
use SocialDept\AtpAppview\Realtime\Realtime;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Auth\ServiceAuth;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpClient\Data\ServiceAuthToken;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Record events after commit, pings on public channels for what a
 * signed-out viewer could see, grouped over a window, and private account
 * channels joined by session or service auth.
 */
class RealtimeTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const POST = 'com.example.post';
    protected const LIKE = 'com.example.like';
    protected const PROFILE = 'app.bsky.actor.profile';
    protected const AUTHORIZE = 'com.example.realtime.authorize';
    protected const SERVICE = 'did:web:app.example';

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
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.middleware', ['web']);
        $app['config']->set('atp-client.service_auth.audience', self::SERVICE.'#atproto_appview');
        $app['config']->set('atp-appview.ingest.collections', [self::POST, self::LIKE, self::PROFILE]);
        $app['config']->set('atp-appview.features', [Features::moderation(), Features::notifications(), Features::realtime()]);
        $app['config']->set('atp-appview.realtime', [
            'collections' => [self::POST],
            'subjects' => [self::LIKE => ['subject.uri']],
            'notifications' => true,
            'window' => 0,
            'xrpc' => ['authorize' => self::AUTHORIZE],
        ]);
        $app['config']->set('broadcasting.default', 'reverb');
        $app['config']->set('broadcasting.connections.reverb', ['driver' => 'reverb', 'key' => 'app-key', 'secret' => 'app-secret', 'app_id' => '1', 'options' => []]);
        $app['config']->set('auth.providers.users.model', RealtimeUser::class);
        $app['config']->set('auth.guards.atp', ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('did');
            $table->rememberToken();
        });
    }

    protected function index(string $did, string $collection, string $rkey, ?array $record, bool $live = true): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, $collection, $rkey, $record === null ? RecordAction::Delete : RecordAction::Create, $record === null ? null : "bafy-{$rkey}", '3l2aaaaaaaaaa', $record === null ? null : $record + ['createdAt' => '2026-10-21T00:00:00Z'], $live),
        ]));

        return "at://{$did}/{$collection}/{$rkey}";
    }

    /**
     * @return array<int, array{0: string, 1: array<string, mixed>}> Channel and data of each ping
     */
    protected function pings(): array
    {
        return Event::dispatched(Ping::class)->map(fn (array $args) => [$args[0]->channel, $args[0]->data])->values()->all();
    }

    public function test_live_records_announce_themselves_after_commit_and_deletes_carry_what_they_held(): void
    {
        Event::fake([RecordIndexed::class, RecordRemoved::class]);

        $post = $this->index(self::ALICE, self::POST, 'p1', ['text' => 'hi']);
        $this->index(self::ALICE, self::POST, 'p0', ['text' => 'old'], live: false);
        $like = $this->index(self::BOB, self::LIKE, 'l1', ['subject' => ['uri' => $post]]);
        $this->index(self::BOB, self::LIKE, 'l1', null);

        Event::assertDispatchedTimes(RecordIndexed::class, 2);
        Event::assertDispatched(RecordIndexed::class, fn (RecordIndexed $event) => $event->uri === $post && $event->record['text'] === 'hi');
        Event::assertNotDispatched(RecordIndexed::class, fn (RecordIndexed $event) => str_ends_with($event->uri, '/p0'));
        Event::assertDispatched(RecordRemoved::class, fn (RecordRemoved $event) => $event->uri === $like && $event->record['subject']['uri'] === $post);
    }

    public function test_a_new_record_pings_its_collection_and_what_it_points_at(): void
    {
        Event::fake([Ping::class]);

        $post = $this->index(self::ALICE, self::POST, 'p1', ['text' => 'hi']);
        $this->index(self::BOB, self::LIKE, 'l1', ['subject' => ['uri' => $post]]);
        $this->index(self::BOB, self::LIKE, 'l1', null);

        $this->assertSame([
            ['appview.collection.'.self::POST, ['kind' => 'collection', 'collection' => self::POST, 'uri' => $post, 'action' => 'create', 'count' => 1]],
            [Realtime::subjectChannel($post), ['kind' => 'subject', 'collection' => self::LIKE, 'uri' => 'at://'.self::BOB.'/'.self::LIKE.'/l1', 'action' => 'create', 'count' => 1]],
            [Realtime::subjectChannel($post), ['kind' => 'subject', 'collection' => self::LIKE, 'uri' => 'at://'.self::BOB.'/'.self::LIKE.'/l1', 'action' => 'delete', 'count' => 1]],
        ], $this->pings(), 'A like is not a listed collection, so only its subject hears of it, even once deleted.');
        $this->assertSame('appview.subject.'.substr(hash('sha256', $post), 0, 32), Realtime::subjectChannel($post));
    }

    public function test_public_channels_never_name_what_a_signed_out_viewer_could_not_see(): void
    {
        Event::fake([Ping::class]);
        DB::table('atp_identities')->insert(['did' => self::BOB, 'handle' => 'bob.test', 'active' => false, 'updated_at' => now()]);
        $this->index(self::ALICE, self::PROFILE, 'self', ['labels' => ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => '!no-unauthenticated']]]]);

        $this->index(self::BOB, self::POST, 'p1', ['text' => 'from an inactive account']);
        $this->index(self::ALICE, self::POST, 'p2', ['text' => 'from someone hidden from guests']);
        $this->index(self::ALICE, self::POST, 'p2', null);

        $this->assertSame([], $this->pings());
    }

    public function test_pings_within_a_window_become_one_with_a_count(): void
    {
        config()->set('atp-appview.realtime.window', 2);
        Queue::fake();
        Event::fake([Ping::class]);

        $this->index(self::ALICE, self::POST, 'p1', ['text' => 'one']);
        $this->index(self::ALICE, self::POST, 'p2', ['text' => 'two']);
        $last = $this->index(self::ALICE, self::POST, 'p3', ['text' => 'three']);

        Queue::assertPushed(FlushPings::class, 1);
        Queue::assertPushed(FlushPings::class, fn (FlushPings $job) => $job->delay === 2);
        $this->assertSame([], $this->pings(), 'Nothing is sent until the window closes.');

        app(Realtime::class)->flush('appview.collection.'.self::POST);
        $this->assertSame([['appview.collection.'.self::POST, ['kind' => 'collection', 'collection' => self::POST, 'uri' => $last, 'action' => 'create', 'count' => 3]]], $this->pings());

        $this->index(self::ALICE, self::POST, 'p4', ['text' => 'four']);
        Queue::assertPushed(FlushPings::class, 2);
    }

    public function test_a_notification_pings_the_recipients_private_channel(): void
    {
        Event::fake([Ping::class]);

        event(new NotificationCreated(1, self::ALICE, 'like', 'at://x', 'g', self::BOB, 'at://y', Carbon::now()));

        $this->assertSame([[Realtime::accountChannel(self::ALICE), ['kind' => 'account', 'collection' => null, 'uri' => null, 'action' => 'notification', 'count' => 1]]], $this->pings());
        $this->assertStringStartsWith('private-appview.account.', Realtime::accountChannel(self::ALICE));
    }

    public function test_an_account_joins_only_its_own_channel_by_session_or_service_auth(): void
    {
        $alice = RealtimeUser::create(['did' => self::ALICE]);
        $own = Realtime::accountChannel(self::ALICE);
        $expected = ['auth' => 'app-key:'.hash_hmac('sha256', "123.456:{$own}", 'app-secret')];

        $this->postJson('/xrpc/'.self::AUTHORIZE, ['socket_id' => '123.456', 'channel_name' => $own])->assertStatus(401);

        $this->actingAs($alice, 'web')->postJson('/xrpc/'.self::AUTHORIZE, ['socket_id' => '123.456', 'channel_name' => $own])
            ->assertOk()->assertExactJson($expected);
        $this->actingAs($alice, 'web')->postJson('/xrpc/'.self::AUTHORIZE, ['socket_id' => '123.456', 'channel_name' => Realtime::accountChannel(self::BOB)])
            ->assertStatus(403);
        $this->actingAs($alice, 'web')->postJson('/xrpc/'.self::AUTHORIZE, ['socket_id' => 'nope', 'channel_name' => $own])
            ->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->mock(ServiceAuth::class, function ($mock) {
            $mock->shouldReceive('fromHeader')->andReturn('jwt');
            $mock->shouldReceive('verify')->with('jwt', self::SERVICE.'#atproto_appview', self::AUTHORIZE)
                ->andReturn(new ServiceAuthToken(self::ALICE, self::SERVICE, self::AUTHORIZE, time() + 60));
        });

        $this->postJson('/xrpc/'.self::AUTHORIZE, ['socket_id' => '123.456', 'channel_name' => $own], ['Authorization' => 'Bearer jwt'])
            ->assertOk()->assertExactJson($expected);

        $this->assertSame(['authorize' => self::AUTHORIZE], FrontendAuth::browserConfig()['realtime']);
    }
}

class RealtimeUser extends Authenticatable implements HasAtpSession
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getAtpDid(): ?string
    {
        return $this->did;
    }
}
