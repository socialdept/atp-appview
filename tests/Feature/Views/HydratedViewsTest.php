<?php

namespace SocialDept\AtpAppview\Tests\Feature\Views;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Index\Refs;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Tests\Fixtures\Views\ShelfView;
use SocialDept\AtpAppview\Tests\Fixtures\Views\ThingView;
use SocialDept\AtpAppview\Views\Hydrator;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class HydratedViewsTest extends TestCase
{
    protected const SHELF = 'at://did:plc:writer/com.example.shelf/main';

    protected int $eventId = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.guards.atp', ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.views', ['com.example.getThings' => ThingView::class]);
        $app['config']->set('atp-appview.ingest.collections', ['com.example.thing', 'com.example.like', 'com.example.shelf']);
        $app['config']->set('atp-appview.ingest.companions', ['app.bsky.actor.profile']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Http::preventStrayRequests();

        $this->index(
            new IdentityEvent(++$this->eventId, 'did:plc:writer', 'writer.test', true, 'active'),
            $this->event('did:plc:writer', 'app.bsky.actor.profile', 'self', ['displayName' => 'The Writer']),
            $this->event('did:plc:writer', 'com.example.shelf', 'main', ['name' => 'Main shelf', 'icon' => ['$type' => 'blob', 'ref' => ['$link' => 'bafyicon'], 'mimeType' => 'image/png']]),
        );
    }

    protected function event(string $did, string $collection, string $rkey, ?array $value, RecordAction $action = RecordAction::Create): RecordEvent
    {
        return new RecordEvent(++$this->eventId, $did, $collection, $rkey, $action, $value === null ? null : "bafy-{$rkey}-{$this->eventId}", '3l2'.str_pad((string) $this->eventId, 10, 'a', STR_PAD_LEFT), $value, true);
    }

    protected function index(object ...$events): void
    {
        app(RecordIndexer::class)->handle(new Batch($events));
    }

    protected function thing(int $n): string
    {
        return "at://did:plc:writer/com.example.thing/t{$n}";
    }

    protected function like(string $did, int $n): RecordEvent
    {
        return $this->event($did, 'com.example.like', "l{$n}", ['subject' => ['uri' => $this->thing($n), 'cid' => 'bafy']]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function thingView(int $n, ?string $viewer = null): array
    {
        return app(Hydrator::class)->one(app(ThingView::class), Record::query()->findOrFail($this->thing($n)), $viewer);
    }

    protected function things(int $count): void
    {
        $this->index(...array_map(fn (int $n) => $this->event('did:plc:writer', 'com.example.thing', "t{$n}", ['title' => "Thing {$n}", 'shelf' => self::SHELF]), range(1, $count)));
    }

    public function test_a_view_carries_its_author_embed_counts_and_the_viewers_own_record(): void
    {
        $this->things(1);
        $this->index($this->like('did:plc:alice', 1), $this->like('did:plc:bob', 1));

        $view = $this->thingView(1, viewer: 'did:plc:alice');

        $this->assertSame(['did' => 'did:plc:writer', 'handle' => 'writer.test', 'displayName' => 'The Writer', 'avatar' => null], $view['author']);
        $this->assertSame(['uri' => self::SHELF, 'name' => 'Main shelf', 'icon' => 'https://img.infra.coop/blob/did:plc:writer/bafyicon/p=avatar-sm'], $view['shelf']);
        $this->assertSame(2, $view['likeCount']);
        $this->assertSame(['like' => 'at://did:plc:alice/com.example.like/l1', 'repost' => null], $view['viewer']);
        $this->assertSame(0, $view['repostCount']);
        $this->assertSame(['like' => null, 'repost' => null], $this->thingView(1)['viewer'], 'No viewer, no viewer state.');
    }

    public function test_a_mapped_query_serves_pages_of_views_and_one_by_uri(): void
    {
        $this->things(3);
        $this->index($this->like('did:plc:alice', 2));

        $this->getJson('/xrpc/com.example.getThings?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'things')
            ->assertJsonPath('things.0.uri', $this->thing(3))
            ->assertJsonPath('things.1.likeCount', 1)
            ->assertJsonStructure(['cursor']);

        $this->getJson('/xrpc/com.example.getThings?uri='.urlencode($this->thing(2)))
            ->assertOk()
            ->assertJsonPath('uri', $this->thing(2))
            ->assertJsonPath('shelf.name', 'Main shelf');
    }

    public function test_counts_follow_deletes_and_account_status(): void
    {
        $this->things(1);
        $this->index($this->like('did:plc:alice', 1), $this->like('did:plc:bob', 1), $this->like('did:plc:carol', 1));
        $this->assertSame(3, $this->thingView(1)['likeCount']);

        // A deleted like stops counting.
        $this->index($this->event('did:plc:carol', 'com.example.like', 'l1', null, RecordAction::Delete));
        $this->assertSame(2, $this->thingView(1)['likeCount']);

        // An account that goes inactive is hidden, and counts again when it returns.
        $this->index(new IdentityEvent(++$this->eventId, 'did:plc:bob', 'bob.test', false, 'deactivated'));
        $this->assertSame(1, $this->thingView(1)['likeCount']);
        $this->index(new IdentityEvent(++$this->eventId, 'did:plc:bob', 'bob.test', true, 'active'));
        $this->assertSame(2, $this->thingView(1)['likeCount']);

        // A deleted account's references go with its records.
        $this->index(new IdentityEvent(++$this->eventId, 'did:plc:alice', 'alice.test', false, 'deleted'));
        $this->assertSame(1, $this->thingView(1)['likeCount']);
        $this->assertSame(0, DB::table(Refs::TABLE)->where('source_did', 'did:plc:alice')->count());
    }

    public function test_a_like_from_an_inactive_account_arrives_hidden(): void
    {
        $this->things(1);
        $this->index(new IdentityEvent(++$this->eventId, 'did:plc:bob', 'bob.test', false, 'deactivated'));
        $this->index($this->like('did:plc:bob', 1));

        $this->assertSame(0, $this->thingView(1)['likeCount']);
        $this->assertSame(1, DB::table(Refs::TABLE)->where('source_did', 'did:plc:bob')->where('hidden', true)->count());
    }

    public function test_a_blocklist_purge_drops_the_purged_records_references(): void
    {
        $this->things(1);
        $this->index($this->like('did:plc:spam', 1), $this->like('did:plc:alice', 1));
        Http::fake();

        app(Blocklist::class)->block('did:plc:spam', purge: true);

        $this->assertSame(1, $this->thingView(1)['likeCount']);
        $this->assertSame(0, DB::table(Refs::TABLE)->where('source_did', 'did:plc:spam')->count());
    }

    public function test_reference_fields_come_from_the_views_and_config(): void
    {
        config(['atp-appview.index.refs' => ['com.example.repost' => ['subject.uri']]]);
        app(Refs::class)->flush();

        // ShelfView is only embedded, so its count is never resolved or kept.
        $this->assertEqualsCanonicalizing(['com.example.like', 'com.example.repost'], array_keys(app(Refs::class)->paths()));
        $this->assertSame(['subject.uri'], app(Refs::class)->paths()['com.example.repost']);
        $this->assertSame(['subject.uri'], app(Refs::class)->paths()['com.example.like']);
    }

    public function test_appview_refs_builds_a_new_field_for_records_already_indexed_and_drops_a_removed_one(): void
    {
        config(['atp-appview.xrpc.views' => []]);
        app(Refs::class)->flush();
        $this->things(2);
        $this->index($this->like('did:plc:alice', 1));
        $this->assertSame(0, DB::table(Refs::TABLE)->count(), 'No view, no references.');

        config(['atp-appview.views' => [ThingView::class]]);
        app(Refs::class)->flush();
        $this->artisan('appview:refs')->assertSuccessful();

        $this->assertSame(1, DB::table(Refs::TABLE)->where('path', 'subject.uri')->count());
        $this->assertSame(2, DB::table(Refs::PATHS_TABLE)->count(), 'Likes and reposts.');

        config(['atp-appview.views' => [ShelfView::class]]);
        app(Refs::class)->flush();
        $this->artisan('appview:refs')->assertSuccessful();

        $this->assertSame(0, DB::table(Refs::TABLE)->where('path', 'subject.uri')->count());
        $this->assertSame(2, DB::table(Refs::TABLE)->where('path', 'shelf')->count());
    }

    public function test_a_record_resent_unchanged_or_out_of_date_leaves_its_references_alone(): void
    {
        $this->things(1);
        $like = $this->like('did:plc:alice', 1);
        $this->index($like);

        // Tap resending the same record at the same rev later, as a resync does.
        $this->travel(5)->seconds();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->index(new RecordEvent(++$this->eventId, $like->did, $like->collection, $like->rkey, $like->action, $like->cid, $like->rev, $like->record, false));
        $writes = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => str_contains($sql, Refs::TABLE));
        $this->assertSame([], array_values($writes), 'Nothing changed, nothing rewritten.');

        // An older version arriving late changes nothing either.
        $this->index(new RecordEvent(++$this->eventId, $like->did, $like->collection, $like->rkey, RecordAction::Update, 'bafy-old', '3l1aaaaaaaaaa', ['subject' => ['uri' => $this->thing(9)]], true));
        $this->assertSame([$this->thing(1)], DB::table(Refs::TABLE)->where('source_did', 'did:plc:alice')->pluck('target_uri')->all());
    }

    public function test_a_batch_outside_every_view_writes_no_references(): void
    {
        DB::enableQueryLog();
        $this->index($this->event('did:plc:writer', 'com.example.other', 'x', ['subject' => ['uri' => $this->thing(1)]]));

        $this->assertEmpty(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => str_contains($sql, Refs::TABLE)));
    }

    public function test_every_count_shares_one_query_and_every_viewer_field_another(): void
    {
        $this->things(2);
        $this->index($this->like('did:plc:alice', 1));

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(Hydrator::class)->hydrate(app(ThingView::class), Record::query()->where('collection', 'com.example.thing')->get(), 'did:plc:alice');
        $refs = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => str_contains($sql, Refs::TABLE)));

        $this->assertCount(2, $refs, 'Two counts and two viewer fields, two queries.');
    }

    public function test_a_page_costs_the_same_number_of_queries_whatever_its_size(): void
    {
        $this->things(30);
        $this->index(...array_map(fn (int $n) => $this->like('did:plc:alice', $n), range(1, 30)));

        $queries = function (int $limit): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/xrpc/com.example.getThings?limit={$limit}")->assertOk()->assertJsonCount($limit, 'things');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        // Once first, so briefly cached lookups such as running backfills are warm for both.
        $queries(1);

        $this->assertSame($queries(2), $queries(25));
    }
}
