<?php

namespace SocialDept\AtpAppview\Tests\Feature\Feeds;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use LogicException;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Feeds\FeedInteractions;
use SocialDept\AtpAppview\Feeds\FeedItem;
use SocialDept\AtpAppview\Tests\Fixtures\Feeds\LatestThings;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpClient\Testing\FakeResponse;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * The feeds feature as shipped, with Bluesky's names, and the commands that
 * set an app up: writing its own lexicons and publishing generator records.
 */
class FeedsSetupTest extends TestCase
{
    protected const PUBLISHER = 'did:plc:publisher';
    protected const SERVICE = 'did:web:feeds.example';

    protected string $lexicons;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.service.endpoint', 'https://feeds.example');
        $app['config']->set('atp-client.service_auth.audience', self::SERVICE);
        $app['config']->set('atp-appview.features', [Features::feeds()]);
        $app['config']->set('atp-appview.feeds.feeds', ['latest' => LatestThings::class]);
        $app['config']->set('atp-appview.feeds.publisher', ['did' => self::PUBLISHER, 'password' => 'app-password']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
        $this->lexicons = sys_get_temp_dir().'/atp-appview-feed-lexicons-'.uniqid();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->lexicons);

        parent::tearDown();
    }

    public function test_bluesky_names_are_the_built_in_default(): void
    {
        $methods = app(AppView::class)->xrpcMethods();

        $this->assertArrayHasKey('app.bsky.feed.getFeedSkeleton', $methods);
        $this->assertArrayHasKey('app.bsky.feed.describeFeedGenerator', $methods);
        $this->assertArrayHasKey('app.bsky.feed.sendInteractions', $methods);
        $this->assertArrayNotHasKey('app.bsky.feed.getFeed', $methods, 'Bluesky serves getFeed from its AppView, so it is off by default.');
        $this->assertSame(['bsky_fg' => 'BskyFeedGenerator'], app(AppView::class)->services());
        $this->assertSame(['$type' => 'app.bsky.feed.defs#skeletonReasonPin'], FeedItem::pin('at://x/y/z')->reason);

        $this->getJson('/.well-known/did.json')->assertJsonPath('service.1.id', '#bsky_fg');
    }

    public function test_the_lexicons_command_writes_nothing_on_bluesky_names(): void
    {
        $this->artisan('appview:feeds:lexicons', ['--path' => $this->lexicons])
            ->expectsOutputToContain('which exist already')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->lexicons);
    }

    public function test_the_lexicons_command_writes_bluesky_shapes_under_the_apps_names(): void
    {
        config()->set('atp-appview.feeds.lexicons', [
            'generator' => 'com.example.feed.generator',
            'defs' => 'com.example.feed.defs',
            'skeleton' => 'com.example.feed.getFeedSkeleton',
            'describe' => 'com.example.feed.describeFeedGenerator',
            'interactions' => 'com.example.feed.sendInteractions',
            'feed' => 'com.example.feed.getFeed',
        ]);

        $this->artisan('appview:feeds:lexicons', ['--path' => $this->lexicons])->assertSuccessful();

        $read = fn (string $name) => json_decode((string) file_get_contents("{$this->lexicons}/com/example/feed/{$name}.json"), true);

        $skeleton = $read('getFeedSkeleton');
        $this->assertSame('com.example.feed.getFeedSkeleton', $skeleton['id']);
        $this->assertSame('com.example.feed.defs#skeletonFeedPost', $skeleton['defs']['main']['output']['schema']['properties']['feed']['items']['ref']);
        $this->assertSame('com.example.feed.defs#interaction', $read('sendInteractions')['defs']['main']['input']['schema']['properties']['interactions']['items']['ref']);
        $this->assertSame('com.example.feed.generator', $read('generator')['id']);
        $this->assertSame(['com.example.feed.defs#skeletonReasonRepost', 'com.example.feed.defs#skeletonReasonPin'], $read('getFeed')['defs']['feedItem']['properties']['reason']['refs']);
        $this->assertContains('com.example.feed.defs#requestLess', $read('defs')['defs']['interaction']['properties']['event']['knownValues']);
        $this->assertArrayHasKey('requestLess', $read('defs')['defs']);
        $this->assertStringNotContainsString('app.bsky.feed.', (string) json_encode([$skeleton, $read('defs'), $read('getFeed'), $read('sendInteractions'), $read('describeFeedGenerator')]));

        // An existing file is kept unless forced.
        file_put_contents("{$this->lexicons}/com/example/feed/defs.json", '{}');
        $this->artisan('appview:feeds:lexicons', ['--path' => $this->lexicons])->assertSuccessful();
        $this->assertSame('{}', file_get_contents("{$this->lexicons}/com/example/feed/defs.json"));
    }

    public function test_publishing_writes_each_feeds_generator_record_and_prunes_the_rest(): void
    {
        $atp = Atp::fake();
        $atp->stub('com.atproto.repo.listRecords', ['records' => [
            ['uri' => 'at://'.self::PUBLISHER.'/app.bsky.feed.generator/latest', 'cid' => 'c1', 'value' => ['createdAt' => '2026-01-01T00:00:00.000Z']],
            ['uri' => 'at://'.self::PUBLISHER.'/app.bsky.feed.generator/retired', 'cid' => 'c2', 'value' => ['createdAt' => '2026-01-02T00:00:00.000Z']],
        ]]);
        $atp->stub('com.atproto.repo.putRecord', FakeResponse::putRecord());
        $atp->stub('com.atproto.repo.deleteRecord', FakeResponse::deleteRecord());

        $this->artisan('appview:feeds:publish', ['--prune' => true])->assertSuccessful();

        $atp->assertCalledWith('com.atproto.repo.putRecord', function ($request) {
            $body = $request->body;

            return $body['collection'] === 'app.bsky.feed.generator'
                && $body['rkey'] === 'latest'
                && $body['record'] == [
                    '$type' => 'app.bsky.feed.generator',
                    'did' => self::SERVICE,
                    'displayName' => 'Latest things',
                    'description' => 'The newest things.',
                    'acceptsInteractions' => true,
                    'createdAt' => '2026-01-01T00:00:00.000Z',
                ];
        });
        $atp->assertCalledWith('com.atproto.repo.deleteRecord', fn ($request) => $request->body['rkey'] === 'retired');
    }

    public function test_publishing_refuses_without_a_password(): void
    {
        config()->set('atp-appview.feeds.publisher.password', null);

        $this->artisan('appview:feeds:publish')->assertFailed();
    }

    public function test_without_the_feature_nothing_is_served_or_kept(): void
    {
        config()->set('atp-appview.features', []);

        $this->expectException(LogicException::class);
        app(FeedInteractions::class)->record('did:plc:alice', 'at://x/y/z', []);
    }
}
