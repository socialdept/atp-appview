<?php

namespace SocialDept\AtpAppview\Tests\Feature\Index;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Index\RecordQuery;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Tests\Fixtures\Views\ThingView;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class RecordFieldsTest extends TestCase
{
    protected const THING = 'com.example.thing';

    protected int $eventId = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.guards.atp', ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web']);
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.fields', [self::THING => [
            'tags' => 'string[]',
            'createdAt' => 'datetime',
            'score' => 'number',
            'parent' => ['type' => 'uri', 'path' => 'reply.parent.uri'],
            'code' => ['type' => 'string', 'case' => 'exact'],
        ]]);
        $app['config']->set('atp-appview.xrpc.views', ['com.example.getThings' => [
            'view' => ThingView::class,
            'filters' => ['tag' => 'tags'],
            'sorts' => ['created' => 'createdAt'],
        ]]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        RecordQuery::$sortFirstAbove = 10000;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function thing(string $did, string $rkey, array $value, RecordAction $action = RecordAction::Create): RecordEvent
    {
        return new RecordEvent(++$this->eventId, $did, self::THING, $rkey, $action, $action === RecordAction::Delete ? null : "bafy-{$rkey}-{$this->eventId}", '3l2'.str_pad((string) $this->eventId, 10, 'a', STR_PAD_LEFT), $action === RecordAction::Delete ? null : $value, true);
    }

    protected function index(object ...$events): void
    {
        app(RecordIndexer::class)->handle(new Batch($events));
    }

    /**
     * @return array<int, string>
     */
    protected function rkeys(RecordQuery $query): array
    {
        return $query->get()['records']->pluck('rkey')->all();
    }

    protected function seedThings(): void
    {
        $this->index(
            $this->thing('did:plc:alice', 'a', ['tags' => ['PHP', 'laravel'], 'createdAt' => '2026-01-03T00:00:00Z', 'score' => 5]),
            $this->thing('did:plc:alice', 'b', ['tags' => ['php'], 'createdAt' => '2026-01-01T00:00:00Z', 'score' => 9]),
            $this->thing('did:plc:bob', 'c', ['tags' => ['rust', 'laravel'], 'createdAt' => '2026-01-02T00:00:00Z', 'score' => 1]),
            // No usable date: left out of date sorts, kept everywhere else.
            $this->thing('did:plc:bob', 'd', ['tags' => ['php'], 'createdAt' => 'not a date', 'reply' => ['parent' => ['uri' => 'at://did:plc:alice/com.example.thing/a']]]),
        );
    }

    public function test_records_filter_by_a_value_case_insensitively_newest_first(): void
    {
        $this->seedThings();

        // Newest by sort_at: d's date cannot be read, so it sorts by when it was indexed.
        $this->assertSame(['d', 'a', 'b'], $this->rkeys(RecordQuery::in(self::THING)->where('tags', 'PhP')));
        $this->assertSame(['d'], $this->rkeys(RecordQuery::in(self::THING)->where('parent', 'at://did:plc:alice/com.example.thing/a')));
        $this->assertSame([], $this->rkeys(RecordQuery::in(self::THING)->where('tags', 'go')));
    }

    public function test_several_filters_are_all_required_and_a_repo_narrows_them(): void
    {
        $this->seedThings();

        $this->assertSame(['a'], $this->rkeys(RecordQuery::in(self::THING)->where('tags', 'php')->where('tags', 'laravel')));
        $this->assertSame(['c'], $this->rkeys(RecordQuery::in(self::THING)->where('tags', 'laravel')->by('did:plc:bob')));
    }

    public function test_records_sort_by_a_date_or_number_and_page_through_a_cursor(): void
    {
        $this->seedThings();

        $this->assertSame(['a', 'c', 'b'], $this->rkeys(RecordQuery::in(self::THING)->orderBy('createdAt')));
        $this->assertSame(['b', 'c', 'a'], $this->rkeys(RecordQuery::in(self::THING)->orderBy('createdAt', 'asc')));
        $this->assertSame(['b', 'a', 'c'], $this->rkeys(RecordQuery::in(self::THING)->orderBy('score')));

        $first = RecordQuery::in(self::THING)->orderBy('createdAt')->limit(2)->get();
        $this->assertSame(['a', 'c'], $first['records']->pluck('rkey')->all());
        $this->assertSame(['b'], $this->rkeys(RecordQuery::in(self::THING)->orderBy('createdAt')->limit(2)->after($first['cursor'])));
    }

    public function test_a_filter_and_a_sort_agree_whichever_drives_the_query(): void
    {
        $this->seedThings();

        // A rare filter is read in full and sorted, a common one checked
        // against the sort's index. Both give the same page.
        $rare = $this->rkeys(RecordQuery::in(self::THING)->where('tags', 'php')->orderBy('createdAt'));
        RecordQuery::$sortFirstAbove = 0;
        $common = $this->rkeys(RecordQuery::in(self::THING)->where('tags', 'php')->orderBy('createdAt'));

        $this->assertSame(['a', 'b'], $rare);
        $this->assertSame($rare, $common);
    }

    public function test_exact_case_fields_match_case_and_unknown_fields_are_refused(): void
    {
        $this->index($this->thing('did:plc:alice', 'a', ['code' => 'AbC']));

        $this->assertSame(['a'], $this->rkeys(RecordQuery::in(self::THING)->where('code', 'AbC')));
        $this->assertSame([], $this->rkeys(RecordQuery::in(self::THING)->where('code', 'abc')));

        $this->expectException(InvalidArgumentException::class);
        RecordQuery::in(self::THING)->where('missing', 'x');
    }

    public function test_counts_follow_edits_deletes_and_account_status(): void
    {
        $this->seedThings();
        $values = app(RecordValues::class);

        $this->assertSame([['value' => 'php', 'total' => 3], ['value' => 'laravel', 'total' => 2], ['value' => 'rust', 'total' => 1]], $values->top(self::THING, 'tags'));

        // An edit drops a tag, a delete drops a record, a deactivation hides an account.
        $this->index($this->thing('did:plc:alice', 'a', ['tags' => ['laravel'], 'createdAt' => '2026-01-03T00:00:00Z'], RecordAction::Update));
        $this->index($this->thing('did:plc:bob', 'd', [], RecordAction::Delete));
        $this->assertSame(1, collect($values->top(self::THING, 'tags'))->firstWhere('value', 'php')['total']);

        $this->index(new IdentityEvent(++$this->eventId, 'did:plc:bob', 'bob.test', false, 'deactivated'));
        $this->assertSame(1, collect($values->top(self::THING, 'tags'))->firstWhere('value', 'laravel')['total']);
        $this->assertSame([], $this->rkeys(RecordQuery::in(self::THING)->where('tags', 'rust')));

        $this->index(new IdentityEvent(++$this->eventId, 'did:plc:bob', 'bob.test', true, 'active'));
        $this->assertSame(2, collect($values->top(self::THING, 'tags'))->firstWhere('value', 'laravel')['total']);
    }

    public function test_suggestions_come_from_the_counts_most_common_first(): void
    {
        $this->index(
            $this->thing('did:plc:alice', 'a', ['tags' => ['theology', 'theory']]),
            $this->thing('did:plc:alice', 'b', ['tags' => ['theory']]),
            $this->thing('did:plc:alice', 'c', ['tags' => ['the_end', 'other']]),
        );

        $this->assertSame(['theory', 'theology'], array_column(app(RecordValues::class)->suggest(self::THING, 'tags', 'THEO'), 'value'));
        $this->assertSame(['the_end'], array_column(app(RecordValues::class)->suggest(self::THING, 'tags', 'the_'), 'value'), 'An underscore is matched literally.');
    }

    public function test_appview_fields_builds_a_field_for_records_already_indexed(): void
    {
        config(['atp-appview.fields' => []]);
        app(RecordValues::class)->flush();
        $this->seedThings();
        $this->assertSame(0, DB::table(RecordValues::VALUES)->count());

        config(['atp-appview.fields' => [self::THING => ['tags' => 'string[]']]]);
        app(RecordValues::class)->flush();
        $this->artisan('appview:fields')->assertSuccessful();

        $this->assertSame(['d', 'a', 'b'], $this->rkeys(RecordQuery::in(self::THING)->where('tags', 'php')));
        $this->assertSame(3, collect(app(RecordValues::class)->top(self::THING, 'tags'))->firstWhere('value', 'php')['total']);

        // Retyped, it is dropped and built again.
        config(['atp-appview.fields' => [self::THING => ['tags' => ['type' => 'string[]', 'case' => 'exact']]]]);
        app(RecordValues::class)->flush();
        $this->artisan('appview:fields')->assertSuccessful();
        $this->assertSame(['b', 'd'], collect($this->rkeys(RecordQuery::in(self::THING)->where('tags', 'php')))->sort()->values()->all());
    }

    public function test_recount_repairs_counts_that_drifted(): void
    {
        $this->seedThings();
        DB::table(RecordValues::COUNTS)->update(['total' => 99]);

        $this->artisan('appview:fields', ['--recount' => true])->assertSuccessful();

        $this->assertSame(3, collect(app(RecordValues::class)->top(self::THING, 'tags'))->firstWhere('value', 'php')['total']);
    }

    public function test_a_view_query_filters_and_sorts_by_its_mapped_fields(): void
    {
        $this->seedThings();

        $this->getJson('/xrpc/com.example.getThings?tag=php')
            ->assertOk()
            ->assertJsonPath('things.*.uri', [
                'at://did:plc:bob/com.example.thing/d',
                'at://did:plc:alice/com.example.thing/a',
                'at://did:plc:alice/com.example.thing/b',
            ]);

        $this->getJson('/xrpc/com.example.getThings?tag=php&sort=created&limit=1')
            ->assertOk()
            ->assertJsonPath('things.0.uri', 'at://did:plc:alice/com.example.thing/a')
            ->assertJsonStructure(['cursor']);

        $this->getJson('/xrpc/com.example.getThings?sort=unknown')->assertStatus(400);
    }

    public function test_a_filtered_page_reads_only_the_values_table_then_records_by_id(): void
    {
        $this->seedThings();

        DB::flushQueryLog();
        DB::enableQueryLog();
        RecordQuery::in(self::THING)->where('tags', 'php')->where('tags', 'laravel')->orderBy('createdAt')->get();
        $queries = array_column(DB::getQueryLog(), 'query');

        $records = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'from "atp_records"')));
        $this->assertCount(1, $records, 'Records are fetched once, by id.');
        $this->assertStringContainsString('"id" in', $records[0]);
    }
}
