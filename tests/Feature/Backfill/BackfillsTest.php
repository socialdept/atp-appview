<?php

namespace SocialDept\AtpAppview\Tests\Feature\Backfill;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Backfill\Backfills;
use SocialDept\AtpAppview\Backfill\BackfillTracker;
use SocialDept\AtpAppview\Backfill\Events\BackfillFinished;
use SocialDept\AtpAppview\Backfill\Events\BackfillStarted;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\IngestStats;
use SocialDept\AtpAppview\Models\Backfill;
use SocialDept\AtpAppview\Models\BackfillRepo;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class BackfillsTest extends TestCase
{
    protected const TAP = 'http://tap.test';
    protected const RELAY = 'https://relay.test';
    protected const READS = 'app.example.read';
    protected const POSTS = 'app.example.post';
    protected const PROFILES = 'app.example.profile';

    /** Repos the fake Tap tracks. */
    protected array $tracked = ['did:plc:followed'];

    protected bool $resyncRoutes = true;

    protected int $outbox = 0;

    protected bool $addFails = false;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('atp-appview.ingest.admin_url', self::TAP);
        $app['config']->set('atp-appview.ingest.discovery_url', self::RELAY);
        $app['config']->set('atp-appview.ingest.collections', [self::POSTS, self::READS]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

        Http::fake([
            self::RELAY.'/*' => Http::response(['repos' => [['did' => 'did:plc:followed'], ['did' => 'did:plc:new']]]),
            self::TAP.'/resync/*' => function (Request $request) {
                if (! $this->resyncRoutes) {
                    return Http::response(['message' => 'Not Found'], 404);
                }

                $did = basename(parse_url($request->url(), PHP_URL_PATH));

                return in_array($did, $this->tracked, true)
                    ? Http::response(['did' => $did, 'state' => 'desynchronized'])
                    : Http::response(['message' => 'repo not found'], 404);
            },
            self::TAP.'/repos/add' => fn () => $this->addFails ? Http::response('down', 500) : Http::response(),
            self::TAP.'/repos/remove' => Http::response(),
            self::TAP.'/stats/outbox-buffer' => fn () => Http::response(['outbox_buffer' => $this->outbox]),
            self::TAP.'/info/*' => fn (Request $request) => Http::response(['did' => basename($request->url()), 'state' => 'error', 'error' => 'failed to get repo: 502']),
        ]);
    }

    protected function backfills(): Backfills
    {
        return $this->app->make(Backfills::class);
    }

    /**
     * @param  array<string, string|null>  $repos  Delivery time by DID
     */
    protected function backfillWith(array $repos, ?string $collection = null, ?Carbon $startedAt = null): Backfill
    {
        $backfill = Backfill::query()->create(['collection' => $collection ?? self::READS, 'source' => 'test', 'started_at' => $startedAt ?? now()]);

        foreach ($repos as $did => $deliveredAt) {
            BackfillRepo::query()->insert(['backfill_id' => $backfill->id, 'did' => $did, 'method' => 'resync', 'delivered_at' => $deliveredAt]);
        }

        $this->backfills()->forget();

        return $backfill;
    }

    protected function backfilled(string $did, string $rkey, bool $live = false, string $collection = self::READS): RecordEvent
    {
        return new RecordEvent(1, $did, $collection, $rkey, RecordAction::Create, 'cid-'.$rkey, '3l2aaaaaaaaaa', ['subject' => 'x'], $live);
    }

    protected function relayCalls(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_starts_with($r->url(), self::RELAY))->values()->all();
    }

    protected function tapCalls(string $path): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_starts_with($r->url(), self::TAP.$path))->values()->all();
    }

    public function test_tap_resyncs_repos_it_follows_and_adds_the_rest(): void
    {
        Event::fake([BackfillStarted::class]);

        $backfill = $this->backfills()->start(self::READS);

        $this->assertSame(['did:plc:followed' => 'resync', 'did:plc:new' => 'add'], $backfill->repos()->orderBy('did')->pluck('method', 'did')->all());
        $this->assertSame([['did:plc:new']], array_map(fn (Request $r) => $r['dids'], $this->tapCalls('/repos/add')));
        $this->assertSame([], $this->tapCalls('/repos/remove'), 'Nothing is removed when Tap can resync.');
        Event::assertDispatched(BackfillStarted::class);
    }

    public function test_running_a_backfill_again_counts_repos_already_indexed_as_delivered(): void
    {
        // Tap already sent this repo's reads, so a resync sends nothing new.
        DB::table('atp_records')->insert(['uri' => 'at://did:plc:followed/'.self::READS.'/1', 'did' => 'did:plc:followed', 'collection' => self::READS, 'rkey' => '1', 'cid' => 'cid', 'record' => '{}', 'rev' => '3l2aaaaaaaaaa', 'indexed_at' => now(), 'sort_at' => now()]);

        $backfill = $this->backfills()->start(self::READS);

        $this->assertNotNull($backfill->repos()->where('did', 'did:plc:followed')->value('delivered_at'));
        $this->assertNull($backfill->repos()->where('did', 'did:plc:new')->value('delivered_at'));
    }

    public function test_a_tap_without_resync_has_repos_removed_and_added_again(): void
    {
        $this->resyncRoutes = false;

        $backfill = $this->backfills()->start(self::READS);

        $this->assertSame(['readd'], $backfill->repos()->distinct()->pluck('method')->all());
        $this->assertSame([['did:plc:followed', 'did:plc:new']], array_map(fn (Request $r) => $r['dids'], $this->tapCalls('/repos/remove')));
        $this->assertSame([['did:plc:followed', 'did:plc:new']], array_map(fn (Request $r) => $r['dids'], $this->tapCalls('/repos/add')));
    }

    public function test_only_one_backfill_of_a_collection_runs_at_a_time(): void
    {
        $this->backfillWith(['did:plc:a' => null]);

        $this->expectException(RuntimeException::class);
        $this->backfills()->start(self::READS);
    }

    public function test_a_backfill_that_could_not_hand_its_repos_to_tap_fails(): void
    {
        $this->addFails = true;

        try {
            $this->backfills()->start(self::READS);
            $this->fail('Expected the failure to surface.');
        } catch (\Throwable) {
        }

        $backfill = Backfill::sole();
        $this->assertSame(Backfill::FAILED, $backfill->status);
        $this->assertStringContainsString('Handing repos to Tap failed', $backfill->error);
    }

    public function test_a_repo_is_delivered_when_its_backfilled_records_arrive_and_live_ones_say_nothing(): void
    {
        $backfill = $this->backfillWith(['did:plc:a' => null, 'did:plc:b' => null, 'did:plc:c' => null]);

        $this->app->make(BackfillTracker::class)->handle(new Batch([
            $this->backfilled('did:plc:a', '1'),
            $this->backfilled('did:plc:a', '2'),
            $this->backfilled('did:plc:b', '3', live: true),
            $this->backfilled('did:plc:c', '4', collection: self::POSTS),
        ]));

        $repos = $backfill->repos()->get()->keyBy('did');
        $this->assertNotNull($repos['did:plc:a']->delivered_at);
        $this->assertSame(2, $repos['did:plc:a']->records);
        $this->assertNull($repos['did:plc:b']->delivered_at);
        $this->assertNull($repos['did:plc:c']->delivered_at);
    }

    public function test_the_first_run_counts_collections_already_holding_records_as_backfilled(): void
    {
        $this->indexRecord(self::POSTS);

        $this->assertSame([self::READS], $this->backfills()->work());

        $this->assertSame([self::POSTS => 1, self::READS => 0], DB::table('atp_ingest_collections')->orderBy('collection')->pluck('baseline', 'collection')->map(fn ($b) => (int) $b)->all());
        $this->assertSame([self::READS], Backfill::query()->pluck('collection')->all());
    }

    public function test_the_first_run_tracks_a_backfill_tap_is_still_running(): void
    {
        $this->indexRecord(self::POSTS);
        $this->tapIsBackfilling();

        $this->assertSame([self::POSTS, self::READS], $this->backfills()->work());

        $this->assertSame(0, (int) DB::table('atp_ingest_collections')->where('collection', self::POSTS)->value('baseline'));
        $this->assertCount(2, $this->tapCalls('/resync/'.'did:plc:followed'));
    }

    public function test_the_first_run_keeps_the_baseline_on_a_tap_without_resync_even_mid_backfill(): void
    {
        $this->resyncRoutes = false;
        $this->indexRecord(self::POSTS);
        $this->tapIsBackfilling();

        $this->assertSame([self::READS], $this->backfills()->work());
        $this->assertSame(1, (int) DB::table('atp_ingest_collections')->where('collection', self::POSTS)->value('baseline'));
    }

    public function test_later_a_new_collection_is_backfilled_even_if_live_records_reached_it_first(): void
    {
        config(['atp-appview.ingest.collections' => [self::POSTS]]);
        $this->indexRecord(self::POSTS);
        $this->backfills()->work();

        config(['atp-appview.ingest.collections' => [self::POSTS, self::READS]]);
        $this->indexRecord(self::READS);

        $this->assertSame([self::READS], $this->backfills()->work());
        $this->assertSame([], $this->backfills()->work());
    }

    public function test_a_collection_dropped_and_added_back_is_backfilled_again(): void
    {
        config(['atp-appview.ingest.collections' => [self::READS]]);
        $this->assertSame([self::READS], $this->backfills()->work());
        Backfill::sole()->update(['status' => Backfill::FINISHED, 'finished_at' => now()]);

        config(['atp-appview.ingest.collections' => []]);
        $this->backfills()->work();
        $this->assertNotNull(DB::table('atp_ingest_collections')->where('collection', self::READS)->value('removed_at'));

        config(['atp-appview.ingest.collections' => [self::READS]]);
        $this->assertSame([self::READS], $this->backfills()->work());
        $this->assertSame(2, Backfill::query()->where('collection', self::READS)->count());
        $this->assertNull(DB::table('atp_ingest_collections')->where('collection', self::READS)->value('removed_at'));
    }

    public function test_a_backfill_whose_repos_all_delivered_waits_for_the_rest_of_their_records(): void
    {
        Event::fake([BackfillFinished::class]);
        $this->markKnown();
        $backfill = $this->backfillWith(['did:plc:a' => null, 'did:plc:b' => null]);

        // Each repo's first records arrive, so every repo has delivered, but
        // Tap is still sending the rest.
        $this->app->make(BackfillTracker::class)->handle(new Batch([$this->backfilled('did:plc:a', '1'), $this->backfilled('did:plc:b', '2')]));
        $this->backfills()->work();
        $this->assertSame(Backfill::RUNNING, $backfill->fresh()->status);

        $this->travel(30)->seconds();
        $this->app->make(BackfillTracker::class)->handle(new Batch([$this->backfilled('did:plc:a', '3')]));
        $this->travel(45)->seconds();
        $this->backfills()->work();
        $this->assertSame(Backfill::RUNNING, $backfill->fresh()->status, 'Records arrived 45 seconds ago.');

        $this->travel(30)->seconds();
        $this->outbox = 2;
        $this->backfills()->work();
        $this->assertSame(Backfill::RUNNING, $backfill->fresh()->status, 'Tap still has events to send.');

        $this->outbox = 0;
        $this->backfills()->work();
        $this->assertSame(Backfill::FINISHED, $backfill->fresh()->status);
        $this->assertSame(3, (int) $backfill->repos()->sum('records'), 'Every record was counted, not just the first.');
        Event::assertDispatched(BackfillFinished::class, fn ($e) => $e->backfill->is($backfill));
    }

    public function test_stragglers_end_a_backfill_only_once_tap_is_quiet_with_its_outbox_empty(): void
    {
        $this->markKnown();
        $backfill = $this->backfillWith(['did:plc:a' => now()->subMinutes(2), 'did:plc:never' => null]);
        $backfill->update(['last_delivery_at' => now()->subMinutes(2)]);

        $this->backfills()->work();
        $this->assertSame(Backfill::RUNNING, $backfill->fresh()->status, 'Tap delivered recently.');

        $backfill->update(['last_delivery_at' => now()->subMinutes(11)]);
        $this->outbox = 3;
        $this->backfills()->work();
        $this->assertSame(Backfill::RUNNING, $backfill->fresh()->status, 'Tap still has events to send.');

        $this->outbox = 0;
        $this->backfills()->work();
        $this->assertSame(Backfill::FINISHED, $backfill->fresh()->status);
        $this->assertSame('failed to get repo: 502', BackfillRepo::query()->where('did', 'did:plc:never')->value('error'), 'Why Tap could not deliver is kept.');
    }

    public function test_a_backfill_that_delivered_nothing_fails_instead_of_finishing(): void
    {
        $this->markKnown();
        $backfill = $this->backfillWith(['did:plc:a' => null, 'did:plc:b' => null], startedAt: now()->subMinutes(11));

        $this->backfills()->work();

        $backfill->refresh();
        $this->assertSame(Backfill::FAILED, $backfill->status);
        $this->assertStringContainsString("Check that it is in Tap's collection filters", $backfill->error);
    }

    public function test_progress_puts_running_backfills_first_least_far_along_first(): void
    {
        $finished = $this->backfillWith(['did:plc:f' => now()], self::POSTS);
        $finished->update(['status' => Backfill::FINISHED, 'finished_at' => now()]);
        $this->backfillWith(['did:plc:x1' => now(), 'did:plc:x2' => now(), 'did:plc:x3' => null], self::POSTS.'.b');
        $this->backfillWith(['did:plc:y1' => now(), 'did:plc:y2' => null, 'did:plc:y3' => null, 'did:plc:y4' => null]);

        $progress = $this->backfills()->progress();

        $this->assertSame([25, 66, 100], array_column($progress, 'percent'));
        $this->assertSame(['running', 'running', 'finished'], array_column($progress, 'status'));
        $this->assertCount(2, $this->backfills()->active());
    }

    public function test_a_companion_collection_backfills_the_repos_already_followed_without_discovering_any(): void
    {
        config(['atp-appview.ingest.companions' => [self::PROFILES]]);
        Identity::query()->insert([
            ['did' => 'did:plc:followed', 'active' => true, 'updated_at' => now()],
            // Known to the index but not followed by Tap, such as a repo only resolved.
            ['did' => 'did:plc:resolved', 'active' => true, 'updated_at' => now()],
            ['did' => 'did:plc:gone', 'active' => false, 'updated_at' => now()],
        ]);

        $backfill = $this->backfills()->start(self::PROFILES);

        $this->assertSame(Backfills::FOLLOWED_REPOS, $backfill->source);
        $this->assertSame(['did:plc:followed' => 'resync'], $backfill->repos()->pluck('method', 'did')->all());
        $this->assertSame([], $this->relayCalls(), 'Nothing is discovered.');
        $this->assertSame([], $this->tapCalls('/repos/add'), 'No repo is added to Tap.');
    }

    public function test_a_companion_collection_added_later_is_backfilled_like_any_other(): void
    {
        $this->backfills()->work();
        Backfill::query()->update(['status' => Backfill::FINISHED, 'finished_at' => now()]);
        Identity::query()->insert(['did' => 'did:plc:followed', 'active' => true, 'updated_at' => now()]);

        config(['atp-appview.ingest.companions' => [self::PROFILES]]);

        $this->assertSame([self::PROFILES], $this->backfills()->work());
        $this->assertSame(Backfills::FOLLOWED_REPOS, Backfill::query()->where('collection', self::PROFILES)->value('source'));
    }

    public function test_the_command_backfills_a_companion_collection(): void
    {
        config(['atp-appview.ingest.companions' => [self::PROFILES]]);

        $this->artisan('appview:backfill', ['collection' => [self::PROFILES]])->assertSuccessful();

        $this->assertSame(1, Backfill::query()->where('collection', self::PROFILES)->count());
    }

    public function test_the_command_refuses_a_collection_that_is_not_ingested(): void
    {
        $this->artisan('appview:backfill', ['collection' => ['app.example.other']])->assertFailed();

        $this->assertSame(0, Backfill::query()->count());
    }

    public function test_the_command_starts_a_backfill_and_says_how_tap_was_asked(): void
    {
        $this->artisan('appview:backfill', ['collection' => [self::READS]])
            ->expectsOutputToContain('Followed, resynced by Tap')
            ->expectsOutputToContain('New to Tap, added')
            ->assertSuccessful();

        $this->assertSame(1, Backfill::query()->where('collection', self::READS)->count());
    }

    /**
     * Mark every configured collection as seen, so work() only finishes.
     */
    protected function markKnown(): void
    {
        foreach ((array) config('atp-appview.ingest.collections') as $collection) {
            DB::table('atp_ingest_collections')->insert(['collection' => $collection, 'first_seen_at' => now(), 'baseline' => true]);
        }
    }

    protected function tapIsBackfilling(): void
    {
        $stats = $this->app->make(IngestStats::class);
        $stats->record(new Batch([$this->backfilled('did:plc:old', 'a', collection: self::POSTS)]));
        $stats->flush();
    }

    protected function indexRecord(string $collection): void
    {
        DB::table('atp_records')->insert([
            'uri' => "at://did:plc:old/{$collection}/".uniqid(),
            'did' => 'did:plc:old',
            'collection' => $collection,
            'rkey' => uniqid(),
            'cid' => 'cid',
            'record' => '{}',
            'rev' => '3l2aaaaaaaaaa',
            'indexed_at' => now(),
            'sort_at' => now(),
        ]);
    }
}
