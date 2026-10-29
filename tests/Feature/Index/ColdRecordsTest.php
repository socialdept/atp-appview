<?php

namespace SocialDept\AtpAppview\Tests\Feature\Index;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Index\ColdRecords;
use SocialDept\AtpAppview\Index\RecordCid;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class ColdRecordsTest extends TestCase
{
    protected const THING = 'com.example.thing';
    protected const LIKE = 'com.example.like';
    protected const SLINGSHOT = 'https://slingshot.test/xrpc/com.atproto.repo.getRecord*';

    protected int $id = 0;

    /**
     * What each record was indexed with, by rkey, for Slingshot to answer.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $stored = [];

    /**
     * A CID for Slingshot to answer with instead of the one asked for.
     */
    protected ?string $answeredCid = null;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.collections', ['com.example.listThings' => self::THING]);
        $app['config']->set('atp-appview.features', [Features::notifications()]);
        $app['config']->set('atp-appview.notifications.reasons', [self::LIKE => ['subject.uri' => 'like']]);
        $app['config']->set('atp-appview.fields', [self::LIKE => ['subject.uri' => 'uri']]);
        $app['config']->set('atp-support.microcosm.slingshot.url', 'https://slingshot.test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    /**
     * A body and the CID a repo would give it.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function body(string $title, string $createdAt = '2024-01-01T00:00:00Z'): array
    {
        $body = ['$type' => self::THING, 'title' => $title, 'createdAt' => $createdAt];

        return [$body, RecordCid::of(json_decode(json_encode($body)))];
    }

    /**
     * Index a record through the pipeline, returning its uri.
     *
     * @param  array<string, mixed>  $record
     */
    protected function index(string $collection, string $rkey, array $record, string $cid, string $rev = '3l2aaaaaaaaaa', string $did = 'did:plc:alice'): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, $did, $collection, $rkey, RecordAction::Create, $cid, $rev, $record, true),
        ]));

        return "at://{$did}/{$collection}/{$rkey}";
    }

    protected function thing(string $rkey, string $createdAt = '2024-01-01T00:00:00Z'): string
    {
        [$body, $cid] = $this->body($rkey, $createdAt);
        $this->stored[$rkey] = $body;

        return $this->index(self::THING, $rkey, $body, $cid);
    }

    /**
     * Slingshot answering with each record's stored body, or the overrides.
     *
     * @param  array<string, array<string, mixed>>  $bodies  Bodies by rkey
     */
    protected function slingshot(array $bodies = [], array $failing = []): void
    {
        Http::fake([self::SLINGSHOT => function (Request $request) use ($bodies, $failing) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (in_array($query['rkey'], $failing, true)) {
                return Http::response(['error' => 'RecordNotFound'], 400);
            }

            return Http::response([
                'uri' => "at://{$query['repo']}/{$query['collection']}/{$query['rkey']}",
                'cid' => $this->answeredCid ?? $query['cid'],
                'value' => $bodies[$query['rkey']] ?? $this->stored[$query['rkey']],
            ]);
        }]);
    }

    protected function prune(): void
    {
        $this->artisan('appview:prune')->assertSuccessful();
    }

    public function test_records_past_the_cold_window_lose_their_body_and_keep_the_rest(): void
    {
        config(['atp-appview.retention.cold' => [self::THING => 30]]);

        $old = $this->thing('old', '2024-01-01T00:00:00Z');
        $recent = $this->thing('recent', Carbon::now('UTC')->subDays(2)->toIso8601ZuluString());
        $like = $this->index(self::LIKE, 'like', ['subject' => ['uri' => $old, 'cid' => 'bafy'], 'createdAt' => '2024-01-01T00:00:00Z'], 'bafy-like', did: 'did:plc:bob');
        DB::table('atp_records')->insert(['uri' => 'at://did:plc:alice/com.example.thing/repaired', 'did' => 'did:plc:alice', 'collection' => self::THING, 'rkey' => 'repaired', 'cid' => 'bafy-r', 'rev' => '3l2aaaaaaaaaa', 'record' => '{}', 'repair' => '{}', 'indexed_at' => '2024-01-01 00:00:00', 'sort_at' => '2024-01-01 00:00:00']);

        $this->artisan('appview:prune')->expectsOutputToContain('hollowed 1 cold records')->assertSuccessful();

        $rows = DB::table('atp_records')->get()->keyBy('rkey');
        $this->assertNull($rows['old']->record);
        $this->assertNotNull($rows['old']->hollowed_at);
        $this->assertSame($this->body('old')[1], $rows['old']->cid, 'The CID stays, to fetch and check the body by.');
        $this->assertNotNull($rows['recent']->record);
        $this->assertNotNull($rows['repaired']->record, 'A repaired body is not the one a fetch would return.');
        $this->assertNotNull($rows['like']->record, 'Collections not named keep their bodies.');
        $this->assertSame(1, DB::table('atp_record_values')->count(), 'References to a cold record stay.');
        $this->assertSame(1, DB::table('atp_notifications')->where('source_uri', $like)->count());
        $this->assertTrue(Record::query()->find($old)->isCold());
    }

    public function test_a_dry_run_counts_without_clearing(): void
    {
        config(['atp-appview.retention.cold' => ['*' => 30]]);
        $this->thing('old');

        $this->artisan('appview:prune --dry-run')->expectsOutputToContain('would hollow 1 cold records')->assertSuccessful();

        $this->assertNull(DB::table('atp_records')->value('hollowed_at'));
    }

    public function test_a_pass_stops_at_the_deadline_and_carries_on_from_where_it_stopped(): void
    {
        config(['atp-appview.retention.cold' => ['*' => 30]]);

        foreach (['a', 'b', 'c'] as $rkey) {
            $this->thing($rkey);
        }

        $cold = app(ColdRecords::class);
        $connection = DB::connection();

        $this->assertSame(['counts' => [], 'done' => false], $cold->hollow($connection, [self::THING], 1, microtime(true) - 1));
        $this->assertSame(['counts' => [self::THING => 3], 'done' => true], $cold->hollow($connection, [self::THING], 1, microtime(true) + 60));

        // A backfill indexing an older record after the pass went by: picked
        // up once the pass starts over the next day.
        $this->thing('0-backfilled', '2023-01-01T00:00:00Z');
        $this->assertSame([], $cold->hollow($connection, [self::THING], 1, microtime(true) + 60)['counts']);

        $this->travel(25)->hours();
        $this->assertSame([self::THING => 1], $cold->hollow($connection, [self::THING], 1, microtime(true) + 60)['counts']);
    }

    public function test_a_newer_version_stores_its_body_again_and_a_resend_stays_cold(): void
    {
        config(['atp-appview.retention.cold' => ['*' => 30]]);
        [$body, $cid] = $this->body('old');
        $uri = $this->index(self::THING, 'old', $body, $cid);
        $this->prune();

        $this->index(self::THING, 'old', $body, $cid);
        $this->assertNull(DB::table('atp_records')->where('uri', $uri)->value('record'), 'The same version again changes nothing.');

        [$newer, $newerCid] = $this->body('newer');
        $this->index(self::THING, 'old', $newer, $newerCid, '3l2bbbbbbbbbb');

        $row = DB::table('atp_records')->where('uri', $uri)->first();
        $this->assertNull($row->hollowed_at);
        $this->assertSame('newer', json_decode($row->record, true)['title']);
    }

    public function test_thaw_fetches_a_page_at_once_checks_it_and_caches_it(): void
    {
        config(['atp-appview.retention.cold' => ['*' => 30]]);
        $this->thing('a');
        $this->thing('b');
        $this->thing('c');
        $this->prune();
        $this->slingshot(bodies: ['b' => ['$type' => self::THING, 'title' => 'tampered', 'createdAt' => '2024-01-01T00:00:00Z']], failing: ['c']);

        $records = Record::query()->orderBy('rkey')->get();
        $missing = app(ColdRecords::class)->thaw($records);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'cid='.urlencode($this->body('a')[1])));
        $this->assertSame(['at://did:plc:alice/com.example.thing/b', 'at://did:plc:alice/com.example.thing/c'], $missing, 'A body whose CID does not match is refused.');
        $this->assertSame('a', $records[0]->record['title']);
        $this->assertFalse($records[0]->isDirty(), 'A fetched body is not a change to save.');
        $this->assertNull($records[1]->record);
        $this->assertNull($records[2]->record);

        // Cached: the body by its CID, and the failures for a few minutes.
        $again = Record::query()->orderBy('rkey')->get();
        app(ColdRecords::class)->thaw($again);
        Http::assertSentCount(3);
        $this->assertSame('a', $again[0]->record['title']);
    }

    public function test_a_body_claiming_the_stored_cid_must_hash_to_it(): void
    {
        config(['atp-appview.retention.cold' => ['*' => 30]]);
        $this->thing('a');
        $this->prune();

        // The response says the right CID, but the body is another one.
        $this->slingshot(bodies: ['a' => ['$type' => self::THING, 'title' => 'not a', 'createdAt' => '2024-01-01T00:00:00Z']]);

        $this->assertSame(['at://did:plc:alice/com.example.thing/a'], app(ColdRecords::class)->thaw(Record::query()->get()));
    }

    public function test_a_body_the_cid_cannot_be_computed_for_must_come_with_the_stored_cid(): void
    {
        config(['atp-appview.retention.cold' => ['*' => 30]]);
        // Bytes have no faithful JSON form, so only the response's CID is checked.
        $body = ['$type' => self::THING, 'title' => 'a', 'data' => ['$bytes' => 'AQID'], 'createdAt' => '2024-01-01T00:00:00Z'];
        $this->stored['a'] = $body;
        $this->index(self::THING, 'a', $body, 'bafyreistored');
        $this->prune();

        $this->slingshot();
        $this->answeredCid = 'bafyreiother';
        $this->assertSame(['at://did:plc:alice/com.example.thing/a'], app(ColdRecords::class)->thaw(Record::query()->get()));

        Cache::flush();
        $this->answeredCid = null;
        $this->assertSame([], app(ColdRecords::class)->thaw(Record::query()->get()));
    }

    public function test_lists_serve_cold_bodies_and_leave_out_the_ones_that_cannot_be_fetched(): void
    {
        config(['atp-appview.retention.cold' => ['*' => 30]]);
        $this->thing('a', '2024-01-03T00:00:00Z');
        $this->thing('b', '2024-01-02T00:00:00Z');
        $this->thing('c', '2024-01-01T00:00:00Z');
        $this->prune();
        Cache::flush();
        $this->slingshot(failing: ['b']);

        $response = $this->getJson('/xrpc/com.example.listThings?limit=2')->assertOk();

        $this->assertSame(['at://did:plc:alice/com.example.thing/a'], $response->json('records.*.uri'));
        $this->assertSame('a', $response->json('records.0.value.title'));

        // The cursor is past b, so the next page starts at c.
        $this->getJson('/xrpc/com.example.listThings?limit=2&cursor='.$response->json('cursor'))
            ->assertOk()
            ->assertJsonPath('records.*.uri', ['at://did:plc:alice/com.example.thing/c']);
    }

    public function test_a_cold_record_that_cannot_be_fetched_is_an_upstream_failure(): void
    {
        config(['atp-appview.retention.cold' => ['*' => 30]]);
        $uri = $this->thing('b');
        $this->prune();
        $this->slingshot(failing: ['b']);

        $this->getJson('/xrpc/com.example.listThings?uri='.urlencode($uri))
            ->assertStatus(502)
            ->assertJsonPath('error', 'UpstreamFailure');
    }

    public function test_a_resend_of_a_cold_record_keeps_its_notifications(): void
    {
        config(['atp-appview.retention.cold' => ['*' => 30]]);
        $subject = $this->index(self::THING, 'post', ['$type' => self::THING, 'title' => 'post', 'createdAt' => '2024-01-01T00:00:00Z'], 'bafy-post', did: 'did:plc:bob');
        $like = ['subject' => ['uri' => $subject, 'cid' => 'bafy'], 'createdAt' => '2024-01-01T00:00:00Z'];
        $uri = $this->index(self::LIKE, 'like', $like, 'bafy-like');
        $this->assertSame(1, DB::table('atp_notifications')->where('source_uri', $uri)->count());

        $this->prune();
        $this->index(self::LIKE, 'like', $like, 'bafy-like');

        $this->assertSame(1, DB::table('atp_notifications')->where('source_uri', $uri)->count());
    }

    public function test_rebuilding_values_reads_cold_bodies(): void
    {
        config(['atp-appview.retention.cold' => ['*' => 30]]);
        $like = ['$type' => self::LIKE, 'subject' => ['uri' => 'at://did:plc:bob/com.example.thing/post', 'cid' => 'bafy'], 'createdAt' => '2024-01-01T00:00:00Z'];
        $this->stored['like'] = $like;
        $this->index(self::LIKE, 'like', $like, RecordCid::of(json_decode(json_encode($like))));
        $this->prune();
        $this->slingshot();

        $values = app(RecordValues::class);
        $values->build($values->field(self::LIKE, 'subject.uri'));

        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('atp_record_values')->count());
    }
}
