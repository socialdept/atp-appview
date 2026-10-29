<?php

namespace SocialDept\AtpAppview\Tests\Feature\Spaces;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Mockery;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Index\RecordQuery;
use SocialDept\AtpAppview\Index\RecordTables;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\ValueHash;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\LabelerEvent;
use SocialDept\AtpAppview\Ingest\Events\LabelEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Labels\LabelIndexer;
use SocialDept\AtpAppview\Labels\LabelPreferences;
use SocialDept\AtpAppview\Labels\Policies;
use SocialDept\AtpAppview\Spaces\Contracts\SpaceAccess;
use SocialDept\AtpAppview\Spaces\SpaceRecordIndexer;
use SocialDept\AtpAppview\Spaces\SpaceRecordQuery;
use SocialDept\AtpAppview\Spaces\SpaceRecordValues;
use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSpaces\AtpSpacesServiceProvider;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Spaces;
use SocialDept\AtpSpaces\SpaceTypeRegistry;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Indexed field values for space records, kept apart from the public ones:
 * written as records sync, dropped with their space, built for records
 * synced before, and read by SpaceRecordQuery to filter and sort. Record
 * filters registered for spaces, muted words and label preferences, apply
 * to space records too.
 */
class SpaceRecordValuesTest extends TestCase
{
    protected const AUTHORITY = 'did:plc:authority';
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const MOD = 'did:plc:mod';
    protected const POST = 'com.example.post';

    protected int $id = 0;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpSpacesServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.fields', [self::POST => ['tags' => 'string[]', 'lang' => 'string', 'publishedAt' => 'datetime', 'score' => 'number']]);
        $app['config']->set('atp-appview.graph.muted_words', [self::POST => ['text' => ['text']]]);
        $app['config']->set('atp-appview.graph.follows', ['collection' => 'com.example.follow', 'path' => 'subject']);
        $app['config']->set('atp-appview.graph.blocks', null);
        $app['config']->set('atp-appview.ingest.collections', ['com.example.follow']);
        $app['config']->set('atp-appview.features', [Features::moderation(), Features::graph(), Features::spaces()]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake();
        $this->artisan('migrate')->run();

        $spaces = Mockery::mock(Spaces::class);
        $spaces->shouldReceive('types')->andReturn(new SpaceTypeRegistry())->byDefault();
        $this->app->instance(Spaces::class, $spaces);

        // Anyone signed in may read, so these tests are about what they see.
        $this->app->instance(SpaceAccess::class, new class () implements SpaceAccess {
            public function allows(SpaceRef $space, ?string $viewer): bool
            {
                return $viewer !== null;
            }
        });

        foreach ([$this->space(), $this->other()] as $space) {
            DB::table('atp_spaces')->insert([
                'space' => (string) $space, 'authority' => self::AUTHORITY, 'type' => $space->type, 'skey' => $space->skey,
                'created_at' => '2026-10-29 00:00:00', 'updated_at' => '2026-10-29 00:00:00',
            ]);
        }
    }

    protected function space(): SpaceRef
    {
        return SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'main');
    }

    protected function other(): SpaceRef
    {
        return SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'other');
    }

    /**
     * Sync records into a space as SpaceSync does, through the space indexer.
     *
     * @param  array<string, mixed>  $record
     */
    protected function sync(string $did, string $rkey, array $record, ?SpaceRef $space = null, RecordAction $action = RecordAction::Create, string $rev = '3l2aaaaaaaaaa'): void
    {
        $space ??= $this->space();
        $body = $action === RecordAction::Delete ? null : $record + ['text' => $rkey, 'createdAt' => '2026-10-29T12:00:00Z'];

        (new Pipeline(app(), [app(SpaceRecordIndexer::class)]))->run(new Batch([
            new RecordEvent(++$this->id, $did, self::POST, $rkey, $action, $action === RecordAction::Delete ? null : "bafy-{$rkey}-{$rev}", $rev, $body, false, space: (string) $space),
        ]));
    }

    protected function read(?string $viewer = self::BOB): SpaceRecordQuery
    {
        return SpaceRecordQuery::in($this->space())->visibleTo($viewer)->collection(self::POST);
    }

    /**
     * @return array<int, string>
     */
    protected function rkeys(SpaceRecordQuery $query): array
    {
        return $query->get()['records']->pluck('rkey')->all();
    }

    protected function values(): int
    {
        return DB::table(SpaceRecordValues::TABLE)->count();
    }

    public function test_synced_records_have_their_values_kept_apart_from_public_ones(): void
    {
        $this->sync(self::BOB, 'a', ['tags' => ['php', 'atproto'], 'lang' => 'en']);

        $id = DB::table('atp_space_records')->where('rkey', 'a')->value('id');
        $tags = app(RecordValues::class)->keyId(app(RecordValues::class)->field(self::POST, 'tags'));
        $rows = DB::table(SpaceRecordValues::TABLE)->where('record_id', $id)->where('key_id', $tags)->get();

        $this->assertNotNull($id);
        $this->assertCount(2, $rows);
        $this->assertSame(ValueHash::of((string) $this->space()), (int) $rows->first()->space_hash);
        $this->assertSame(0, DB::table('atp_record_values')->count(), 'Nothing reaches the public values.');
        $this->assertSame(0, (int) DB::table('atp_value_counts')->sum('total'), 'Nor their counts.');
    }

    public function test_an_update_rewrites_the_values_and_a_delete_drops_them(): void
    {
        $this->sync(self::BOB, 'a', ['tags' => ['php']]);
        $this->assertSame(['a'], $this->rkeys($this->read()->where('tags', 'php')));

        $this->sync(self::BOB, 'a', ['tags' => ['laravel']], action: RecordAction::Update, rev: '3l2aaaaaaaaab');
        $this->assertSame([], $this->rkeys($this->read()->where('tags', 'php')));
        $this->assertSame(['a'], $this->rkeys($this->read()->where('tags', 'laravel')));

        // An older revision sent again changes nothing.
        $this->sync(self::BOB, 'a', ['tags' => ['php']], action: RecordAction::Update, rev: '3l2aaaaaaaaaa');
        $this->assertSame(['a'], $this->rkeys($this->read()->where('tags', 'laravel')));

        $this->sync(self::BOB, 'a', [], action: RecordAction::Delete, rev: '3l2aaaaaaaaac');
        $this->assertSame([], $this->rkeys($this->read()->where('tags', 'laravel')));
        $this->assertSame(0, $this->values());
    }

    public function test_filters_page_newest_first_within_one_space(): void
    {
        foreach (['a' => '01', 'b' => '02', 'c' => '03', 'd' => '04'] as $rkey => $second) {
            $this->sync(self::BOB, $rkey, ['tags' => $rkey === 'c' ? ['go'] : ['php'], 'lang' => $rkey === 'a' ? 'fr' : 'en', 'createdAt' => "2026-10-29T12:00:{$second}Z"]);
        }

        $this->sync(self::BOB, 'elsewhere', ['tags' => ['php'], 'lang' => 'en'], $this->other());

        $first = $this->read()->where('tags', 'PHP')->limit(2)->get();
        $second = $this->read()->where('tags', 'php')->limit(2)->after($first['cursor'])->get();

        $this->assertSame(['d', 'b'], $first['records']->pluck('rkey')->all());
        $this->assertSame(['a'], $second['records']->pluck('rkey')->all());
        $this->assertNull($second['cursor']);
        $this->assertSame(['d', 'b'], $this->rkeys($this->read()->where('tags', 'php')->where('lang', 'en')));
        $this->assertSame(['d', 'b'], $this->rkeys($this->read()->where('lang', 'en')->where('tags', 'php')), 'In either order.');
        $this->assertSame([], $this->rkeys($this->read()->where('tags', 'rust')));
        $this->assertSame(['b', 'd'], $this->rkeys($this->read()->where('tags', 'php')->where('lang', 'en')->orderBy(null, 'asc')));
    }

    public function test_records_sort_by_a_date_or_number_field_in_keyset_pages(): void
    {
        $this->sync(self::BOB, 'a', ['publishedAt' => '2026-01-03T00:00:00Z', 'score' => 2, 'tags' => ['php']]);
        $this->sync(self::BOB, 'b', ['publishedAt' => '2026-01-01T00:00:00Z', 'score' => 10, 'tags' => ['php']]);
        $this->sync(self::BOB, 'c', ['publishedAt' => '2026-01-02T00:00:00Z', 'score' => 5, 'tags' => ['go']]);
        $this->sync(self::BOB, 'undated', ['tags' => ['php']]);

        $first = $this->read()->orderBy('publishedAt')->limit(2)->get();
        $second = $this->read()->orderBy('publishedAt')->limit(2)->after($first['cursor'])->get();

        $this->assertSame(['a', 'c'], $first['records']->pluck('rkey')->all());
        $this->assertSame(['b'], $second['records']->pluck('rkey')->all(), 'Records without the field are left out.');
        $this->assertArrayNotHasKey('sort_value', $first['records']->first()->getAttributes());
        $this->assertSame(['b', 'a'], $this->rkeys($this->read()->where('tags', 'php')->orderBy('publishedAt', 'asc')));
        $this->assertSame(['a', 'c', 'b'], $this->rkeys($this->read()->orderBy('score', 'asc')));

        $first = $this->read()->orderBy('score')->limit(1)->get();
        $this->assertSame(['c'], $this->rkeys($this->read()->orderBy('score')->limit(1)->after($first['cursor'])));
    }

    public function test_filtering_needs_a_collection_and_an_indexed_field(): void
    {
        $this->sync(self::BOB, 'a', ['tags' => ['php']]);

        try {
            SpaceRecordQuery::in($this->space())->visibleTo(self::BOB)->where('tags', 'php')->get();
            $this->fail('Fields are declared per collection.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('collection()', $e->getMessage());
        }

        try {
            $this->read()->orderBy('tags')->get();
            $this->fail('Only dates and numbers sort.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sorts only by date and number', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->read()->where('nope', 'php')->get();
    }

    public function test_filters_still_find_nothing_for_a_viewer_the_space_does_not_allow(): void
    {
        $this->sync(self::BOB, 'a', ['tags' => ['php']]);

        $this->assertSame([], $this->rkeys($this->read(null)->where('tags', 'php')));
    }

    public function test_moderation_applies_to_filtered_queries(): void
    {
        $this->sync(self::ALICE, 'blocked', ['tags' => ['php']]);
        $this->sync(self::CAROL, 'taken-down', ['tags' => ['php']]);
        $this->sync(self::BOB, 'own', ['tags' => ['php']]);

        app(AppView::class)->readFilter(fn (string $viewer) => $viewer === self::BOB ? [self::ALICE] : []);
        DB::table('atp_identities')->insert(['did' => self::CAROL, 'active' => false, 'status' => 'takendown', 'updated_at' => '2026-10-29 00:00:00']);

        $this->assertSame(['own'], $this->rkeys($this->read()->where('tags', 'php')));
    }

    public function test_muted_words_leave_space_records_out(): void
    {
        $this->sync(self::ALICE, 'quiet', ['text' => 'a calm picnic', 'tags' => ['php']]);
        $this->sync(self::ALICE, 'loud', ['text' => 'a violent protest', 'tags' => ['php']]);

        app(Graph::class)->muteWord(self::BOB, 'violent');

        $this->assertSame(['quiet'], $this->rkeys($this->read()));
        $this->assertSame(['quiet'], $this->rkeys($this->read()->where('tags', 'php')));
        $this->assertEqualsCanonicalizing(['quiet', 'loud'], $this->rkeys($this->read(self::CAROL)), 'Only for the viewer who muted it.');
    }

    public function test_muted_words_excused_for_followed_accounts_read_the_space_record_author(): void
    {
        $this->sync(self::ALICE, 'loud', ['text' => 'a violent protest']);
        $this->sync(self::CAROL, 'louder', ['text' => 'violent again']);

        // Bob follows Alice, publicly.
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(++$this->id, self::BOB, 'com.example.follow', 'f', RecordAction::Create, 'bafy-f', '3l2aaaaaaaaaa', ['subject' => self::ALICE], false),
        ]));
        app(Graph::class)->muteWord(self::BOB, 'violent', excludeFollowing: true);

        $this->assertSame(['loud'], $this->rkeys($this->read()));
    }

    public function test_label_preferences_leave_space_records_out(): void
    {
        (new LabelIndexer())->handle(new Batch([new LabelerEvent(1, self::MOD, null, ['policies' => [
            'labelValues' => ['rude'],
            'labelValueDefinitions' => [['identifier' => 'rude', 'severity' => 'alert', 'blurs' => 'none', 'defaultSetting' => 'warn']],
        ]], false)]));
        app(Policies::class)->forget();

        $this->sync(self::ALICE, 'rude', ['tags' => ['php']]);
        $this->sync(self::ALICE, 'fine', ['tags' => ['php']]);
        $this->sync(self::BOB, 'own', ['tags' => ['php']]);
        $this->sync(self::CAROL, 'troll', ['tags' => ['php']]);

        foreach ([[(string) $this->space()->record(self::ALICE, self::POST, 'rude'), 'rude'], [(string) $this->space()->record(self::BOB, self::POST, 'own'), 'rude'], [self::CAROL, 'rude']] as [$uri, $val]) {
            (new LabelIndexer())->handle(new Batch([new LabelEvent(++$this->id, self::MOD, $uri, null, $val, false, '2026-01-01T00:00:00.000Z', null, true)]));
        }

        $this->assertCount(4, $this->read()->get()['records'], 'A warning alone hides nothing.');

        app(LabelPreferences::class)->set(self::BOB, 'rude', 'hide');

        $this->assertEqualsCanonicalizing(['fine', 'own'], $this->rkeys($this->read()), "On the record or its author, and never the viewer's own.");
        $this->assertEqualsCanonicalizing(['fine', 'own'], $this->rkeys($this->read()->where('tags', 'php')));
        $this->assertCount(4, $this->read(self::ALICE)->get()['records']);
    }

    public function test_a_record_filter_reads_space_records_only_when_registered_for_them(): void
    {
        $this->sync(self::BOB, 'a', ['tags' => ['php']]);
        $public = [];
        $spaces = [];

        app(AppView::class)->recordFilter(function ($query, string $viewer, string $idColumn, RecordTables $tables) use (&$public) {
            $public[] = $tables->records;
        });
        app(AppView::class)->recordFilter(function ($query, string $viewer, string $idColumn, RecordTables $tables) use (&$spaces) {
            $spaces[] = [$idColumn, $tables->records, $tables->values];
            $query->whereRaw('1 = 0');
        }, spaces: true);

        $this->assertSame([], $this->rkeys($this->read()));
        $this->assertSame([], $public, 'A filter not registered for spaces never sees a space query.');
        $this->assertSame([['atp_space_records.id', 'atp_space_records', 'atp_space_record_values']], $spaces);

        RecordQuery::in(self::POST)->where('tags', 'php')->visibleTo(self::BOB)->get();
        $this->assertSame(['atp_records'], $public);
        $this->assertSame('atp_records', $spaces[1][1]);
    }

    public function test_unfollowing_or_deleting_a_space_drops_its_values_only(): void
    {
        $this->sync(self::BOB, 'a', ['tags' => ['php']]);
        $this->sync(self::BOB, 'b', ['tags' => ['php']], $this->other());

        $other = DB::table(SpaceRecordValues::TABLE)->where('space_hash', ValueHash::of((string) $this->other()))->count();

        app(SpaceSync::class)->deleted($this->space());
        $this->assertGreaterThan(0, $other);
        $this->assertSame($other, $this->values());

        app(SpaceSync::class)->unfollow($this->other());
        $this->assertSame(0, $this->values());
    }

    public function test_the_fields_command_builds_values_for_records_synced_before(): void
    {
        $this->sync(self::BOB, 'a', ['tags' => ['php']]);
        $this->sync(self::BOB, 'b', ['tags' => ['go']]);
        DB::table(SpaceRecordValues::TABLE)->delete();
        DB::table('atp_index_keys')->update(['spaces_built_at' => null]);

        $this->artisan('appview:fields')->expectsOutputToContain('Built '.self::POST.'#tags in spaces.')->assertSuccessful();

        $this->assertSame(['a'], $this->rkeys($this->read()->where('tags', 'php')));
        $this->assertSame(0, DB::table('atp_index_keys')->whereNull('spaces_built_at')->count());

        $built = $this->values();
        $this->artisan('appview:fields')->expectsOutputToContain('Every field is built.')->assertSuccessful();
        $this->assertSame($built, $this->values(), 'A second run builds nothing.');
    }

    public function test_the_fields_command_drops_values_of_a_removed_field(): void
    {
        $this->sync(self::BOB, 'a', ['tags' => ['php'], 'lang' => 'en']);
        $this->artisan('appview:fields')->assertSuccessful();
        $lang = app(RecordValues::class)->keyId(app(RecordValues::class)->field(self::POST, 'lang'));

        config()->set('atp-appview.fields', [self::POST => ['tags' => 'string[]']]);
        app(RecordValues::class)->flush();
        $this->artisan('appview:fields')->assertSuccessful();

        $this->assertSame(0, DB::table(SpaceRecordValues::TABLE)->where('key_id', $lang)->count());
        $this->assertSame(['a'], $this->rkeys($this->read()->where('tags', 'php')));
    }
}
