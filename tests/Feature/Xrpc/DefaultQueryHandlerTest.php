<?php

namespace SocialDept\AtpAppview\Tests\Feature\Xrpc;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Xrpc\Handlers\DefaultQueryHandler;
use SocialDept\AtpAppview\Xrpc\XrpcRegistry;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class DefaultQueryHandlerTest extends TestCase
{
    protected const COLLECTION = 'com.example.thing';

    protected int $eventId = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.collections', [
            'com.example.listThings' => self::COLLECTION,
            'com.example.allThings' => self::COLLECTION,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    public function test_records_list_newest_first_by_when_they_were_made_not_when_indexed(): void
    {
        // Indexed in one batch, as a backfill would, out of creation order.
        $this->index(
            $this->thing('did:plc:alice', 'a', '2024-01-01T00:00:00Z'),
            $this->thing('did:plc:alice', 'c', '2026-01-01T00:00:00Z'),
            $this->thing('did:plc:alice', 'b', '2025-01-01T00:00:00Z'),
        );

        $this->getJson('/xrpc/com.example.listThings?limit=5')
            ->assertOk()
            ->assertJsonPath('records.*.value.title', ['c', 'b', 'a']);
    }

    public function test_the_response_has_the_list_records_shape(): void
    {
        $this->index($this->thing('did:plc:alice', 'only', '2025-01-01T00:00:00Z'));

        $this->getJson('/xrpc/com.example.listThings')
            ->assertOk()
            ->assertExactJson(['records' => [[
                'uri' => 'at://did:plc:alice/com.example.thing/only',
                'cid' => 'bafy-only',
                'value' => ['$type' => self::COLLECTION, 'title' => 'only', 'createdAt' => '2025-01-01T00:00:00Z'],
            ]]]);
    }

    public function test_pages_walk_every_record_once_even_with_identical_times(): void
    {
        $events = [];

        foreach (range(1, 7) as $i) {
            // Several records share a creation time, so the uri breaks ties.
            $events[] = $this->thing('did:plc:alice', "t{$i}", $i <= 4 ? '2025-01-01T00:00:00Z' : "2025-01-0{$i}T00:00:00Z");
        }

        $this->index(...$events);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->getJson('/xrpc/com.example.listThings?limit=3'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
            $seen = [...$seen, ...array_column($response->json('records'), 'uri')];
            $cursor = $response->json('cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertCount(7, $seen);
        $this->assertSame(array_unique($seen), $seen);
    }

    public function test_a_cursor_gives_the_index_a_bound_to_seek_to(): void
    {
        $this->index(...array_map(fn (int $i) => $this->thing('did:plc:alice', "t{$i}", "2025-01-0{$i}T00:00:00Z"), range(1, 3)));
        $cursor = $this->getJson('/xrpc/com.example.listThings?limit=1')->json('cursor');

        DB::enableQueryLog();
        $this->getJson('/xrpc/com.example.listThings?limit=1&cursor='.$cursor)->assertOk();
        $this->getJson('/xrpc/com.example.listThings?limit=1&reverse=true&cursor='.$cursor)->assertOk();
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));

        // Without a plain range on sort_at, the index matches only the
        // collection and a deep page scans every row before the cursor.
        $this->assertStringContainsString('"sort_at" <= ?', $sql);
        $this->assertStringContainsString('"sort_at" >= ?', $sql);
    }

    public function test_the_last_page_carries_no_cursor(): void
    {
        $this->index($this->thing('did:plc:alice', 'a', '2025-01-01T00:00:00Z'), $this->thing('did:plc:alice', 'b', '2025-01-02T00:00:00Z'));

        $this->getJson('/xrpc/com.example.listThings?limit=2')->assertOk()->assertJsonMissingPath('cursor');
    }

    public function test_reverse_lists_oldest_first_and_pages_that_way(): void
    {
        $this->index(
            $this->thing('did:plc:alice', 'a', '2024-01-01T00:00:00Z'),
            $this->thing('did:plc:alice', 'b', '2025-01-01T00:00:00Z'),
            $this->thing('did:plc:alice', 'c', '2026-01-01T00:00:00Z'),
        );

        $first = $this->getJson('/xrpc/com.example.listThings?reverse=true&limit=2')->assertJsonPath('records.*.value.title', ['a', 'b']);

        $this->getJson('/xrpc/com.example.listThings?reverse=true&limit=2&cursor='.$first->json('cursor'))
            ->assertJsonPath('records.*.value.title', ['c']);
    }

    public function test_did_or_a_handle_narrows_to_one_repo(): void
    {
        $this->index(
            $this->thing('did:plc:alice', 'mine', '2025-01-01T00:00:00Z'),
            $this->thing('did:plc:bob', 'theirs', '2025-01-02T00:00:00Z'),
            new IdentityEvent(++$this->eventId, 'did:plc:alice', 'alice.example', true, 'active'),
        );

        $this->getJson('/xrpc/com.example.listThings?did=did:plc:alice')->assertJsonPath('records.*.value.title', ['mine']);
        $this->getJson('/xrpc/com.example.listThings?actor=alice.example')->assertJsonPath('records.*.value.title', ['mine']);
        $this->getJson('/xrpc/com.example.listThings?actor=nobody.example')->assertStatus(400)->assertJson(['error' => 'InvalidRequest']);
    }

    public function test_a_uri_returns_one_record_in_the_get_record_shape(): void
    {
        $this->index($this->thing('did:plc:alice', 'one', '2025-01-01T00:00:00Z'));

        $this->getJson('/xrpc/com.example.listThings?uri='.urlencode('at://did:plc:alice/com.example.thing/one'))
            ->assertOk()
            ->assertJsonPath('uri', 'at://did:plc:alice/com.example.thing/one')
            ->assertJsonPath('cid', 'bafy-one')
            ->assertJsonPath('value.title', 'one');
    }

    public function test_a_uri_outside_the_collection_or_deleted_is_not_found(): void
    {
        $this->index(
            new RecordEvent(++$this->eventId, 'did:plc:alice', 'com.example.other', 'x', RecordAction::Create, 'bafy', '3l2aaaaaaaaaa', ['title' => 'x'], true),
            $this->thing('did:plc:alice', 'gone', '2025-01-01T00:00:00Z'),
        );
        $this->index(new RecordEvent(++$this->eventId, 'did:plc:alice', self::COLLECTION, 'gone', RecordAction::Delete, null, '3l2aaaaaaaaab', null, true));

        foreach (['at://did:plc:alice/com.example.other/x', 'at://did:plc:alice/com.example.thing/gone'] as $uri) {
            $this->getJson('/xrpc/com.example.listThings?uri='.urlencode($uri))
                ->assertStatus(404)
                ->assertJson(['error' => 'RecordNotFound']);
        }

        $this->getJson('/xrpc/com.example.listThings')->assertExactJson(['records' => []]);
    }

    public function test_limits_follow_the_lexicon_or_the_defaults(): void
    {
        $events = array_map(fn ($i) => $this->thing('did:plc:alice', "r{$i}", '2025-01-01T00:00:00Z'), range(1, 6));
        $this->index(...$events);

        // listThings declares default 2 and maximum 5.
        $this->assertCount(2, $this->getJson('/xrpc/com.example.listThings')->json('records'));
        $this->getJson('/xrpc/com.example.listThings?limit=6')->assertStatus(400);

        // allThings declares no limit, so the default is 50 and the cap 100.
        $this->assertCount(6, $this->getJson('/xrpc/com.example.allThings')->json('records'));
        $this->assertCount(6, $this->getJson('/xrpc/com.example.allThings?limit=500')->json('records'));
    }

    public function test_a_malformed_cursor_is_an_invalid_request(): void
    {
        $this->getJson('/xrpc/com.example.listThings?cursor=not-a-cursor')->assertStatus(400)->assertJson(['error' => 'InvalidRequest', 'message' => 'Malformed cursor.']);
    }

    public function test_default_methods_are_registered_without_auth_and_listed(): void
    {
        $method = $this->app->make(XrpcRegistry::class)->find('com.example.listThings');

        $this->assertSame(DefaultQueryHandler::class, $method->handler);
        $this->assertSame(self::COLLECTION, $method->collection);
        $this->artisan('appview:routes')->expectsOutputToContain(DefaultQueryHandler::class)->assertSuccessful();
    }

    public function test_a_handler_class_for_the_same_method_takes_precedence(): void
    {
        config()->set('atp-appview.xrpc.collections', ['com.example.getThing' => self::COLLECTION]);
        config()->set('atp-appview.xrpc.handlers', [\SocialDept\AtpAppview\Tests\Fixtures\Xrpc\GetThing::class]);

        $this->assertSame(\SocialDept\AtpAppview\Tests\Fixtures\Xrpc\GetThing::class, (new XrpcRegistry())->find('com.example.getThing')->handler);
    }

    public function test_mapping_a_procedure_or_a_missing_lexicon_fails_loudly(): void
    {
        config()->set('atp-appview.xrpc.collections', ['com.example.createThing' => self::COLLECTION]);

        try {
            (new XrpcRegistry())->all();
            $this->fail('A procedure should not get a default handler.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('only serve queries', $e->getMessage());
        }

        config()->set('atp-appview.xrpc.collections', ['com.example.nope' => self::COLLECTION]);
        $this->expectException(InvalidArgumentException::class);

        (new XrpcRegistry())->all();
    }

    protected function index(RecordEvent|IdentityEvent ...$events): void
    {
        $this->app->make(RecordIndexer::class)->handle(new Batch($events));
    }

    protected function thing(string $did, string $rkey, string $createdAt): RecordEvent
    {
        return new RecordEvent(++$this->eventId, $did, self::COLLECTION, $rkey, RecordAction::Create, "bafy-{$rkey}", '3l2aaaaaaaaaa', [
            '$type' => self::COLLECTION,
            'title' => $rkey,
            'createdAt' => $createdAt,
        ], false);
    }
}
