<?php

namespace SocialDept\AtpAppview\Tests\Feature\Lexicons;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use SocialDept\AtpAppview\Lexicons\FollowedLexiconsHandler;
use SocialDept\AtpAppview\Lexicons\FollowResult;
use SocialDept\AtpAppview\Lexicons\LexiconFollower;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpSchema\Parser\SchemaLoader;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use SocialDept\AtpSupport\Resolver;
use SocialDept\AtpSupport\Resolvers\LexiconDnsResolver;

class LexiconFollowerTest extends TestCase
{
    protected const NSID = 'com.other.thing';

    protected const AUTHOR = 'did:plc:author';

    protected string $dir;

    /**
     * What the author publishes right now, or null for nothing.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $published = null;

    protected int $status = 200;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->dir = sys_get_temp_dir().'/atp-appview-lexicons-'.bin2hex(random_bytes(4));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('atp-schema.sources', []);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-schema.cache.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.lexicons.follow', [self::NSID]);
        $app['config']->set('atp-appview.lexicons.path', $this->dir);
        $app['config']->set('atp-appview.ingest.admin_url', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

        $resolver = $this->createStub(Resolver::class);
        $resolver->method('resolvePds')->willReturn('https://pds.example');
        $this->app->instance(LexiconDnsResolver::class, new LexiconDnsResolver($resolver, dnsLookup: fn (string $host) => $host === '_lexicon.other.com' ? [['txt' => 'did='.self::AUTHOR]] : false));

        Http::fake(fn () => match (true) {
            $this->status !== 200 => Http::response('down', $this->status),
            $this->published === null => Http::response(['error' => 'RecordNotFound', 'message' => 'Could not locate record'], 400),
            default => Http::response([
                'uri' => 'at://'.self::AUTHOR.'/com.atproto.lexicon.schema/'.self::NSID,
                'cid' => 'bafy-'.md5(json_encode($this->published)),
                'value' => ['$type' => 'com.atproto.lexicon.schema', ...$this->published],
            ]),
        });

        $this->published = self::version(['name' => ['type' => 'string', 'maxLength' => 100]], ['name']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    protected static function version(array $properties, array $required, string $key = 'tid'): array
    {
        return [
            'lexicon' => 1,
            'id' => self::NSID,
            'defs' => ['main' => ['type' => 'record', 'key' => $key, 'record' => ['type' => 'object', 'required' => $required, 'properties' => $properties]]],
        ];
    }

    protected function follower(): LexiconFollower
    {
        return app(LexiconFollower::class);
    }

    protected function file(): array
    {
        return json_decode(file_get_contents($this->dir.'/com/other/thing.json'), true);
    }

    public function test_a_followed_lexicon_is_stored_written_and_loadable(): void
    {
        $result = $this->follower()->sync(self::NSID);

        $this->assertSame(FollowResult::ADDED, $result->outcome);
        $this->assertSame(1, $result->revision);
        $this->assertSame($this->published, $this->file());
        $this->assertSame(self::AUTHOR, $this->follower()->current(self::NSID)->did);

        // The path is a schema source, so the lexicon loads like the app's own.
        $this->assertContains($this->dir, config('atp-schema.sources'));
        $this->assertTrue(app(SchemaLoader::class)->find(self::NSID)->isRecord());
    }

    public function test_an_unchanged_version_changes_nothing(): void
    {
        $this->follower()->sync(self::NSID);

        $this->assertSame(FollowResult::UNCHANGED, $this->follower()->sync(self::NSID)->outcome);
        $this->assertSame(1, (int) $this->follower()->current(self::NSID)->revision);
    }

    public function test_a_compatible_version_is_put_in_use(): void
    {
        $this->follower()->sync(self::NSID);
        $this->published = self::version(['name' => ['type' => 'string', 'maxLength' => 100], 'note' => ['type' => 'string', 'maxLength' => 50]], ['name']);

        $result = $this->follower()->sync(self::NSID);

        $this->assertSame(FollowResult::UPDATED, $result->outcome);
        $this->assertSame(2, $result->revision);
        $this->assertArrayHasKey('note', $this->file()['defs']['main']['record']['properties']);
        $this->assertSame(2, app('db')->table('atp_lexicon_versions')->where('nsid', self::NSID)->count());
    }

    public function test_a_breaking_version_is_held_back_until_accepted(): void
    {
        Log::spy();
        $this->follower()->sync(self::NSID);
        $original = $this->file();
        $this->published = self::version(['name' => ['type' => 'string', 'maxLength' => 100]], ['name'], key: 'any');

        $result = $this->follower()->sync(self::NSID);

        $this->assertSame(FollowResult::REFUSED, $result->outcome);
        $this->assertSame(['record-key-type'], array_map(fn ($issue) => $issue->name, $result->issues));
        $this->assertSame($original, $this->file());
        $this->assertNotNull($this->follower()->current(self::NSID)->pending_schema);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Held back a new version of com.other.thing'));

        $this->artisan('appview:lexicons')
            ->expectsOutputToContain('a newer version is held back')
            ->expectsOutputToContain('record-key-type')
            ->assertSuccessful();

        $this->artisan('appview:lexicons:accept', ['nsid' => self::NSID])->expectsOutputToContain('revision 2')->assertSuccessful();

        $this->assertSame('any', $this->file()['defs']['main']['key']);
        $this->assertNull($this->follower()->current(self::NSID)->pending_schema);
        $this->assertTrue((bool) app('db')->table('atp_lexicon_versions')->where('revision', 2)->value('forced'));
        $this->artisan('appview:lexicons:accept', ['nsid' => self::NSID])->assertFailed();
    }

    public function test_force_puts_a_breaking_version_in_use(): void
    {
        $this->follower()->sync(self::NSID);
        $this->published = self::version(['name' => ['type' => 'integer']], ['name']);

        $this->artisan('appview:lexicons:sync', ['--force' => true])->expectsOutputToContain('updated to revision 2')->assertSuccessful();

        $this->assertSame('integer', $this->file()['defs']['main']['record']['properties']['name']['type']);
    }

    public function test_an_author_that_reverts_clears_the_held_back_version(): void
    {
        $this->follower()->sync(self::NSID);
        $good = $this->published;
        $this->published = self::version(['name' => ['type' => 'integer']], ['name']);
        $this->follower()->sync(self::NSID);
        $this->published = $good;

        $this->assertSame(FollowResult::UNCHANGED, $this->follower()->sync(self::NSID)->outcome);
        $this->assertNull($this->follower()->current(self::NSID)->pending_schema);
    }

    public function test_an_unpublished_lexicon_and_an_unreachable_author_are_told_apart(): void
    {
        $this->published = null;
        $this->assertSame(FollowResult::NOT_PUBLISHED, $this->follower()->sync(self::NSID)->outcome);

        $this->status = 503;
        $this->artisan('appview:lexicons:sync')->expectsOutputToContain('answered HTTP 503')->assertFailed();

        $this->expectException(RuntimeException::class);
        $this->follower()->sync(self::NSID);
    }

    public function test_sync_rewrites_every_file_for_a_fresh_server(): void
    {
        $this->follower()->sync(self::NSID);
        exec('rm -rf '.escapeshellarg($this->dir));

        $this->artisan('appview:lexicons:sync')->expectsOutputToContain('1 lexicons written')->assertSuccessful();

        $this->assertSame($this->published, $this->file());
    }

    public function test_a_change_tap_delivers_syncs_the_lexicon(): void
    {
        $event = fn (string $rkey) => new RecordEvent(0, self::AUTHOR, 'com.atproto.lexicon.schema', $rkey, RecordAction::Update, 'bafy', '3l2aaaaaaaaaa', ['lexicon' => 1], true);

        (new FollowedLexiconsHandler())->handle(new Batch([$event('com.unfollowed.thing')]));
        $this->assertNull($this->follower()->current(self::NSID));

        (new FollowedLexiconsHandler())->handle(new Batch([$event(self::NSID)]));
        $this->assertSame(1, (int) $this->follower()->current(self::NSID)->revision);

        // A failing author never fails the batch.
        $this->status = 503;
        (new FollowedLexiconsHandler())->handle(new Batch([$event(self::NSID)]));
        $this->addToAssertionCount(1);
    }

    public function test_a_followed_lexicon_validates_records(): void
    {
        config()->set('atp-appview.index.validate', 'drop');
        $this->follower()->sync(self::NSID);

        app(RecordIndexer::class)->handle(new Batch([
            new RecordEvent(0, 'did:plc:alice', self::NSID, 'good', RecordAction::Create, 'bafy1', '3l2aaaaaaaaaa', ['name' => 'ok'], true),
            new RecordEvent(0, 'did:plc:alice', self::NSID, 'bad', RecordAction::Create, 'bafy2', '3l2aaaaaaaaaa', ['note' => 'no name'], true),
        ]));

        $this->assertNotNull(Record::find('at://did:plc:alice/com.other.thing/good'));
        $this->assertNull(Record::find('at://did:plc:alice/com.other.thing/bad'));
    }

    public function test_tap_is_asked_to_follow_the_author(): void
    {
        config()->set('atp-appview.ingest.admin_url', 'http://tap.example');
        $tap = Mockery::mock(TapAdmin::class);
        $tap->shouldReceive('addRepos')->once()->with([self::AUTHOR]);
        $this->app->instance(TapAdmin::class, $tap);

        $this->follower()->sync(self::NSID);
    }
}
