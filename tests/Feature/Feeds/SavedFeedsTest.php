<?php

namespace SocialDept\AtpAppview\Tests\Feature\Feeds;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Feeds\Feeds;
use SocialDept\AtpAppview\Feeds\SavedFeeds;
use SocialDept\AtpAppview\Tests\Fixtures\Feeds\LatestThings;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Each viewer's saved and pinned feeds, as Bluesky's savedFeedsPrefV2 has
 * them, kept by the app, with defaults for viewers who never changed theirs.
 */
class SavedFeedsTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const LATEST = 'at://did:plc:publisher/app.bsky.feed.generator/latest';
    protected const OTHER = 'at://did:plc:someone/app.bsky.feed.generator/cats';

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
        $app['config']->set('atp-appview.features', [Features::feeds()]);
        $app['config']->set('atp-appview.feeds.feeds', ['latest' => LatestThings::class]);
        $app['config']->set('atp-appview.feeds.publisher', ['did' => 'did:plc:publisher']);
        $app['config']->set('atp-appview.feeds.saved', [
            ['type' => 'timeline', 'value' => 'following', 'pinned' => true],
            ['type' => 'feed', 'value' => 'latest', 'pinned' => true],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->artisan('migrate')->run();
    }

    protected function saved(): SavedFeeds
    {
        return app(SavedFeeds::class);
    }

    public function test_a_viewer_who_never_changed_theirs_has_the_defaults_without_storing_them(): void
    {
        $this->assertSame([
            ['type' => 'timeline', 'value' => 'following', 'pinned' => true],
            ['type' => 'feed', 'value' => self::LATEST, 'pinned' => true],
        ], $this->saved()->for(self::ALICE));
        $this->assertSame(0, DB::table('atp_saved_feeds')->count());
    }

    public function test_saving_pinning_unpinning_and_removing(): void
    {
        $this->saved()->save(self::ALICE, self::OTHER);
        $this->assertSame([
            ['type' => 'timeline', 'value' => 'following', 'pinned' => true],
            ['type' => 'feed', 'value' => self::LATEST, 'pinned' => true],
            ['type' => 'feed', 'value' => self::OTHER, 'pinned' => false],
        ], $this->saved()->for(self::ALICE), 'Saved but not pinned, after the defaults.');

        $this->saved()->pin(self::ALICE, self::OTHER);
        $this->assertSame([self::LATEST, self::OTHER], array_column(array_slice($this->saved()->pinned(self::ALICE), 1), 'value'));

        $this->saved()->unpin(self::ALICE, self::LATEST);
        $this->assertSame([
            ['type' => 'timeline', 'value' => 'following', 'pinned' => true],
            ['type' => 'feed', 'value' => self::OTHER, 'pinned' => true],
            ['type' => 'feed', 'value' => self::LATEST, 'pinned' => false],
        ], $this->saved()->for(self::ALICE), 'Unpinned stays saved, after the pinned.');

        $this->saved()->remove(self::ALICE, self::LATEST);
        $this->assertNotContains(self::LATEST, array_column($this->saved()->for(self::ALICE), 'value'));

        $this->assertCount(2, $this->saved()->for(self::BOB), 'Bob still has the defaults.');
    }

    public function test_saving_a_saved_feed_again_keeps_its_place(): void
    {
        $this->saved()->save(self::ALICE, self::LATEST);

        $this->assertSame([
            ['type' => 'timeline', 'value' => 'following', 'pinned' => true],
            ['type' => 'feed', 'value' => self::LATEST, 'pinned' => true],
        ], $this->saved()->for(self::ALICE));
    }

    public function test_replacing_sets_the_order_and_keeps_a_repeat_once(): void
    {
        $this->saved()->replace(self::ALICE, [
            ['type' => 'feed', 'value' => self::OTHER, 'pinned' => true],
            ['type' => 'timeline', 'value' => 'following', 'pinned' => true],
            ['type' => 'feed', 'value' => self::LATEST],
            ['type' => 'feed', 'value' => self::OTHER, 'pinned' => false],
        ]);

        $this->assertSame([
            ['type' => 'feed', 'value' => self::OTHER, 'pinned' => true],
            ['type' => 'timeline', 'value' => 'following', 'pinned' => true],
            ['type' => 'feed', 'value' => self::LATEST, 'pinned' => false],
        ], $this->saved()->for(self::ALICE));

        // Emptied on purpose, it stays empty rather than falling back to the defaults.
        $this->saved()->replace(self::ALICE, []);
        $this->assertSame([], $this->saved()->for(self::ALICE));
    }

    public function test_an_entry_needs_a_type_and_a_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->saved()->replace(self::ALICE, [['type' => 'feed', 'value' => '']]);
    }

    public function test_the_catalog_lists_the_feeds_this_service_runs(): void
    {
        $this->assertSame([self::LATEST => [
            'uri' => self::LATEST,
            'rkey' => 'latest',
            'displayName' => 'Latest things',
            'description' => 'The newest things.',
            'acceptsInteractions' => true,
        ]], Feeds::catalog());
    }
}
