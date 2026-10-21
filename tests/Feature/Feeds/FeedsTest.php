<?php

namespace SocialDept\AtpAppview\Tests\Feature\Feeds;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Facades\Xrpc;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Feeds\FeedInteractions;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Tests\Fixtures\Feeds\LatestThings;
use SocialDept\AtpAppview\Tests\Fixtures\Views\ShelfView;
use SocialDept\AtpAppview\Tests\Fixtures\Views\ThingView;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Auth\ServiceAuth;
use SocialDept\AtpClient\Data\ServiceAuthToken;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Feeds under an app's own lexicons: a skeleton of any AT-URIs, the same
 * page hydrated into views for the app's own clients, interactions kept per
 * viewer, and the feed service in the DID document.
 */
class FeedsTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const PUBLISHER = 'did:plc:publisher';
    protected const SERVICE = 'did:web:feeds.example';
    protected const THING = 'com.example.thing';
    protected const FEED = 'at://did:plc:publisher/com.example.feed.generator/latest';

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
        $app['config']->set('atp-appview.service.endpoint', 'https://feeds.example');
        $app['config']->set('atp-client.service_auth.audience', self::SERVICE.'#atproto_appview');
        $app['config']->set('atp-appview.views', [ThingView::class, ShelfView::class]);
        $app['config']->set('atp-appview.features', [Features::graph(), Features::feeds()]);
        $app['config']->set('atp-appview.feeds', [
            'service' => ['id' => 'example_feedgen', 'type' => 'ExampleFeedGenerator'],
            'lexicons' => [
                'generator' => 'com.example.feed.generator',
                'defs' => 'com.example.feed.defs',
                'skeleton' => 'com.example.feed.getFeedSkeleton',
                'describe' => 'com.example.feed.describeFeedGenerator',
                'interactions' => 'com.example.feed.sendInteractions',
                'feed' => 'com.example.feed.getFeed',
            ],
            'feeds' => ['latest' => LatestThings::class],
            'publisher' => ['did' => self::PUBLISHER],
            'links' => ['privacyPolicy' => 'https://feeds.example/privacy'],
        ]);
        $app['config']->set('auth.providers.users.model', FeedsUser::class);
        $app['config']->set('auth.guards.atp', ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        LatestThings::$pinned = [];
        LatestThings::$last = null;
        $this->artisan('migrate')->run();
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('did');
            $table->rememberToken();
        });
    }

    protected function thing(string $did, string $rkey, string $day): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, self::THING, $rkey, RecordAction::Create, "bafy-{$rkey}", '3l2aaaaaaaaaa', ['text' => $rkey, 'createdAt' => "2026-10-{$day}T00:00:00Z"], true),
        ]));

        return 'at://'.$did.'/'.self::THING.'/'.$rkey;
    }

    protected function things(): array
    {
        return [
            'a' => $this->thing(self::ALICE, 'a', '05'),
            'b' => $this->thing(self::BOB, 'b', '04'),
            'c' => $this->thing(self::CAROL, 'c', '03'),
            'd' => $this->thing(self::BOB, 'd', '02'),
        ];
    }

    public function test_the_skeleton_pages_through_any_at_uris_under_the_apps_own_names(): void
    {
        $things = $this->things();
        LatestThings::$pinned = ['at://did:plc:dave/com.example.photo/p1'];

        $first = Xrpc::as(null)->query('com.example.feed.getFeedSkeleton', ['feed' => self::FEED, 'limit' => 3]);

        $this->assertSame([
            ['post' => 'at://did:plc:dave/com.example.photo/p1', 'reason' => ['$type' => 'com.example.feed.defs#skeletonReasonPin']],
            ['post' => $things['a'], 'feedContext' => 'latest'],
            ['post' => $things['b'], 'feedContext' => 'latest'],
        ], $first['feed']);
        $this->assertSame('2', $first['cursor']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first['reqId']);

        $second = Xrpc::as(null)->query('com.example.feed.getFeedSkeleton', ['feed' => self::FEED, 'limit' => 2, 'cursor' => $first['cursor']]);
        $this->assertSame([$things['c'], $things['d']], array_column($second['feed'], 'post'));
        $this->assertArrayNotHasKey('cursor', $second);
    }

    public function test_the_viewer_and_languages_reach_the_feed_from_a_pds_signed_token(): void
    {
        $this->mock(ServiceAuth::class, function ($mock) {
            $mock->shouldReceive('fromHeader')->andReturn('jwt');
            $mock->shouldReceive('verify')->with('jwt', self::SERVICE.'#atproto_appview', 'com.example.feed.getFeedSkeleton')
                ->andReturn(new ServiceAuthToken(self::ALICE, self::SERVICE, 'com.example.feed.getFeedSkeleton', time() + 60));
        });

        $this->getJson('/xrpc/com.example.feed.getFeedSkeleton?feed='.urlencode(self::FEED).'&limit=500', ['Authorization' => 'Bearer jwt', 'Accept-Language' => 'pt-BR, en;q=0.8'])
            ->assertOk();

        $this->assertSame(self::ALICE, LatestThings::$last->viewer);
        $this->assertSame(100, LatestThings::$last->limit, 'A limit past 100 is held to 100.');
        $this->assertSame(['pt-BR', 'en'], LatestThings::$last->languages);
    }

    public function test_a_feed_this_service_does_not_run_is_unknown(): void
    {
        foreach ([
            'at://did:plc:publisher/com.example.feed.generator/nope',
            'at://did:plc:someone-else/com.example.feed.generator/latest',
            'at://did:plc:publisher/app.bsky.feed.generator/latest',
        ] as $feed) {
            try {
                Xrpc::as(null)->query('com.example.feed.getFeedSkeleton', ['feed' => $feed]);
                $this->fail("{$feed} was served.");
            } catch (XrpcException $e) {
                $this->assertSame('UnknownFeed', $e->error);
            }
        }
    }

    public function test_the_hydrated_feed_gives_views_drops_what_the_viewer_should_not_see_and_passes_unknown_collections_through(): void
    {
        $things = $this->things();
        LatestThings::$pinned = ['at://did:plc:dave/com.example.photo/p1'];
        app(Graph::class)->mute(self::ALICE, self::CAROL);

        $page = Xrpc::as(self::ALICE)->query('com.example.feed.getFeed', ['feed' => self::FEED]);

        $this->assertSame(['at://did:plc:dave/com.example.photo/p1', $things['a'], $things['b'], $things['d']], array_map(fn (array $item) => $item['post']['uri'], $page['feed']));
        $this->assertSame(['uri' => 'at://did:plc:dave/com.example.photo/p1'], $page['feed'][0]['post'], 'No view for photos, so the bare URI.');
        $this->assertSame('a', $page['feed'][1]['post']['record']['text']);
        $this->assertSame('latest', $page['feed'][1]['feedContext']);
        $this->assertArrayHasKey('author', $page['feed'][1]['post']);
        $this->assertArrayHasKey('reqId', $page);

        // Signed out, Carol's thing is there.
        $this->assertCount(5, Xrpc::as(null)->query('com.example.feed.getFeed', ['feed' => self::FEED])['feed']);
    }

    public function test_an_indexed_record_without_a_view_is_still_dropped_when_the_viewer_should_not_see_it(): void
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, self::CAROL, 'com.example.photo', 'p1', RecordAction::Create, 'bafy-p1', '3l2aaaaaaaaaa', ['createdAt' => '2026-10-01T00:00:00Z'], true),
        ]));
        LatestThings::$pinned = ['at://'.self::CAROL.'/com.example.photo/p1'];
        app(Graph::class)->mute(self::ALICE, self::CAROL);

        $this->assertSame([], Xrpc::as(self::ALICE)->query('com.example.feed.getFeed', ['feed' => self::FEED])['feed']);
        $this->assertSame([['post' => ['uri' => 'at://'.self::CAROL.'/com.example.photo/p1'], 'reason' => ['$type' => 'com.example.feed.defs#skeletonReasonPin']]], Xrpc::as(self::BOB)->query('com.example.feed.getFeed', ['feed' => self::FEED])['feed']);
    }

    public function test_interactions_are_kept_per_viewer_and_a_feed_can_act_on_them(): void
    {
        $things = $this->things();

        Xrpc::as(self::ALICE)->procedure('com.example.feed.sendInteractions', [
            'feed' => self::FEED,
            'interactions' => [
                ['item' => $things['b'], 'event' => 'com.example.feed.defs#requestLess', 'feedContext' => 'latest', 'reqId' => 'r1'],
                ['item' => $things['c'], 'event' => 'com.example.feed.defs#interactionSeen'],
                ['event' => 'com.example.feed.defs#requestLess'],
            ],
        ]);

        $interactions = app(FeedInteractions::class);
        $this->assertSame([$things['b']], $interactions->items(self::ALICE, self::FEED, FeedInteractions::REQUEST_LESS));
        $this->assertSame([self::BOB => 1], $interactions->authors(self::ALICE, self::FEED, 'requestLess'));
        $this->assertSame(['latest' => 1], $interactions->contexts(self::ALICE, self::FEED, 'requestLess'));
        $this->assertSame([$things['c']], $interactions->items(self::ALICE, self::FEED, 'interactionSeen'));
        $this->assertSame([], $interactions->items(self::BOB, self::FEED, 'requestLess'));

        // The fixture leaves out authors Alice asked for less of, for Alice only.
        $this->assertSame([$things['a'], $things['c']], array_column(Xrpc::as(self::ALICE)->query('com.example.feed.getFeedSkeleton', ['feed' => self::FEED])['feed'], 'post'));
        $this->assertCount(4, Xrpc::as(self::BOB)->query('com.example.feed.getFeedSkeleton', ['feed' => self::FEED])['feed']);
    }

    public function test_interactions_need_a_viewer_and_a_feed_this_service_runs(): void
    {
        try {
            Xrpc::as(null)->procedure('com.example.feed.sendInteractions', ['feed' => self::FEED, 'interactions' => []]);
            $this->fail('Interactions were kept without a viewer.');
        } catch (XrpcException $e) {
            $this->assertSame(401, $e->status);
        }

        $this->expectException(XrpcException::class);
        Xrpc::as(self::ALICE)->procedure('com.example.feed.sendInteractions', ['feed' => 'at://did:plc:publisher/com.example.feed.generator/nope', 'interactions' => []]);
    }

    public function test_old_interactions_are_pruned(): void
    {
        $this->travelTo('2026-01-01 00:00:00');
        app(FeedInteractions::class)->record(self::ALICE, self::FEED, [['item' => 'at://did:plc:bob/com.example.thing/b', 'event' => 'requestLess']]);
        $this->travelTo('2026-03-01 00:00:00');
        app(FeedInteractions::class)->record(self::ALICE, self::FEED, [['item' => 'at://did:plc:bob/com.example.thing/d', 'event' => 'requestLess']]);
        $this->travelTo('2026-04-15 00:00:00');

        $this->artisan('appview:feeds:prune')->assertSuccessful();

        $this->assertSame(['at://did:plc:bob/com.example.thing/d'], app(FeedInteractions::class)->items(self::ALICE, self::FEED, 'requestLess'));
    }

    public function test_describe_lists_the_feeds_and_the_did_document_names_the_feed_service(): void
    {
        $this->assertSame([
            'did' => self::SERVICE,
            'feeds' => [['uri' => self::FEED]],
            'links' => ['privacyPolicy' => 'https://feeds.example/privacy'],
        ], Xrpc::as(null)->query('com.example.feed.describeFeedGenerator'));

        $this->getJson('/.well-known/did.json')
            ->assertOk()
            ->assertJsonPath('service.0.id', '#atproto_appview')
            ->assertJsonPath('service.1', ['id' => '#example_feedgen', 'type' => 'ExampleFeedGenerator', 'serviceEndpoint' => 'https://feeds.example']);
    }
}

class FeedsUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
