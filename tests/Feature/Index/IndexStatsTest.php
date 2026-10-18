<?php

namespace SocialDept\AtpAppview\Tests\Feature\Index;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Console\ReconcileCommand;
use SocialDept\AtpAppview\Index\IndexStats;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class IndexStatsTest extends TestCase
{
    protected const POSTS = 'com.example.post';
    protected const LIKES = 'com.example.like';

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('atp-appview.ingest.collections', [self::POSTS, self::LIKES]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    protected function record(string $did, string $collection, string $rkey, array $state = []): void
    {
        DB::table('atp_records')->insert([
            'uri' => "at://{$did}/{$collection}/{$rkey}",
            'did' => $did,
            'collection' => $collection,
            'rkey' => $rkey,
            'cid' => 'cid-'.$rkey,
            'record' => '{}',
            'rev' => '3l2aaaaaaaaaa',
            'indexed_at' => now(),
            'sort_at' => now(),
            ...$state,
        ]);
    }

    public function test_it_counts_records_hidden_records_tombstones_and_repos(): void
    {
        $this->record('did:plc:alice', self::POSTS, 'a');
        $this->record('did:plc:alice', self::POSTS, 'b');
        $this->record('did:plc:bob', self::POSTS, 'c', ['hidden_at' => now()]);
        $this->record('did:plc:carol', self::POSTS, 'd', ['deleted_at' => now(), 'hidden_at' => now()]);
        $this->record('did:plc:bob', self::LIKES, 'e');
        $this->record('did:plc:alice', self::LIKES, 'f');

        $stats = app(IndexStats::class);
        $stats->refresh();

        // Bob's only post is hidden and Carol's is gone, so only Alice holds
        // a post anyone can see. Across collections Alice and Bob count once.
        $this->assertSame([
            self::LIKES => ['records' => 2, 'hidden' => 0, 'tombstones' => 0, 'repos' => 2],
            self::POSTS => ['records' => 2, 'hidden' => 1, 'tombstones' => 1, 'repos' => 1],
        ], $stats->collections());
        $this->assertSame(['records' => 4, 'hidden' => 1, 'tombstones' => 1, 'repos' => 2], $stats->totals());
        $this->assertNotNull($stats->countedAt());
    }

    public function test_a_recount_replaces_the_last_one(): void
    {
        $this->record('did:plc:alice', self::POSTS, 'a');
        $this->record('did:plc:alice', self::LIKES, 'b');

        $stats = app(IndexStats::class);
        $stats->refresh();

        DB::table('atp_records')->where('collection', self::LIKES)->delete();
        $stats->refresh();

        $this->assertSame([self::POSTS], array_keys($stats->collections()));
        $this->assertSame(1, $stats->totals()['records']);
    }

    public function test_nothing_is_reported_before_the_first_count(): void
    {
        $stats = app(IndexStats::class);

        $this->assertSame([], $stats->collections());
        $this->assertSame(['records' => 0, 'hidden' => 0, 'tombstones' => 0, 'repos' => 0], $stats->totals());
        $this->assertNull($stats->countedAt());
    }

    public function test_an_empty_index_counts_as_empty(): void
    {
        $stats = app(IndexStats::class);
        $stats->refresh();

        $this->assertSame([], $stats->collections());
        $this->assertSame(['records' => 0, 'hidden' => 0, 'tombstones' => 0, 'repos' => 0], $stats->totals());
        $this->assertNotNull($stats->countedAt());
    }

    public function test_the_command_counts_and_reports(): void
    {
        $this->record('did:plc:alice', self::POSTS, 'a');

        $this->artisan('appview:stats')
            ->expectsOutputToContain(self::POSTS)
            ->expectsOutputToContain('Counted in')
            ->assertSuccessful();

        $this->assertSame(1, app(IndexStats::class)->totals()['records']);
    }

    public function test_hidden_records_and_tombstones_have_their_own_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('atp_records'))->pluck('name');

        $this->assertContains('atp_records_hidden_in_sort_order', $indexes);
        $this->assertContains('atp_records_tombstones_in_sort_order', $indexes);
    }

    public function test_repos_never_checked_come_first_then_the_least_recently_checked(): void
    {
        Carbon::setTestNow('2026-10-18 12:00:00');

        foreach (['did:plc:recent' => now()->subHour(), 'did:plc:never' => null, 'did:plc:oldest' => now()->subDays(3)] as $did => $at) {
            Identity::query()->insert(['did' => $did, 'active' => true, 'updated_at' => now(), 'reconciled_at' => $at]);
        }

        $order = ReconcileCommand::neverCheckedFirst(Identity::query())->pluck('did')->all();

        $this->assertSame(['did:plc:never', 'did:plc:oldest', 'did:plc:recent'], $order);
    }
}
