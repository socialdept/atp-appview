<?php

namespace SocialDept\AtpAppview\Tests\Feature\Status;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\IndexStats;
use SocialDept\AtpAppview\Index\ReconcileErrors;
use SocialDept\AtpAppview\Index\ReconcileResult;
use SocialDept\AtpAppview\Models\Block;
use SocialDept\AtpAppview\Models\FollowedLexicon;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Status\AppviewStatus;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class AppviewStatusTest extends TestCase
{
    protected const POSTS = 'app.example.post';
    protected const LIKES = 'app.example.like';

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.ingest.admin_url', 'http://tap.test');
        $app['config']->set('atp-appview.ingest.collections', [self::POSTS, self::LIKES]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
    }

    protected function appview(): AppviewStatus
    {
        return $this->app->make(AppviewStatus::class);
    }

    protected function record(string $did, string $collection, string $rkey, array $state = []): void
    {
        DB::table('atp_records')->insert([
            'uri' => "at://{$did}/{$collection}/{$rkey}", 'did' => $did, 'collection' => $collection, 'rkey' => $rkey,
            'cid' => "cid-{$rkey}", 'record' => '{}', 'rev' => '3l2aaaaaaaaaa', 'indexed_at' => now(), 'sort_at' => now(), ...$state,
        ]);
    }

    public function test_it_reports_tap(): void
    {
        Http::fake([
            'tap.test/health' => Http::response('ok'),
            'tap.test/stats/repo-count' => Http::response(['repo_count' => 18]),
            'tap.test/stats/outbox-buffer' => Http::response(['outbox_buffer' => 3]),
            'tap.test/stats/resync-buffer' => Http::response(['resync_buffer' => 1]),
            'tap.test/stats/cursors' => Http::response(['firehose' => 42]),
        ]);

        $this->assertSame(['healthy' => true, 'repos' => 18, 'outbox' => 3, 'resync' => 1, 'cursor' => 42], $this->appview()->tap());
    }

    public function test_tap_being_down_is_reported_not_thrown(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $tap = $this->appview()->tap();
        $this->assertFalse($tap['healthy']);
        $this->assertArrayHasKey('error', $tap);
    }

    public function test_it_reports_collections_including_empty_ones_and_counts_on_first_use(): void
    {
        $this->record('did:plc:alice', self::POSTS, 'a');
        $this->record('did:plc:bob', self::POSTS, 'b', ['hidden_at' => now()]);

        $this->assertNull($this->app->make(IndexStats::class)->countedAt());

        $this->assertSame([
            ['nsid' => self::POSTS, 'records' => 1, 'hidden' => 1, 'tombstones' => 0, 'repos' => 1],
            ['nsid' => self::LIKES, 'records' => 0, 'hidden' => 0, 'tombstones' => 0, 'repos' => 0],
        ], $this->appview()->collections());
        $this->assertSame(['records' => 1, 'hidden' => 1, 'tombstones' => 0, 'repos' => 1], $this->appview()->totals());
        $this->assertNotNull($this->appview()->countedAt());
    }

    public function test_it_tells_index_writes_from_records_the_network_made(): void
    {
        $this->record('did:plc:alice', self::POSTS, 'new');
        // Rewritten by a resync just now, but made a week ago.
        $this->record('did:plc:alice', self::POSTS, 'old', ['sort_at' => now()->subWeek()]);

        $this->assertSame(2, $this->appview()->writes()['minute']);
        $this->assertSame(1, $this->appview()->recentRecords());
        $this->assertNull($this->appview()->storage(), 'Only measured on Postgres.');
    }

    public function test_it_reports_accounts_and_reconciliation_errors_by_kind(): void
    {
        $now = now();
        Identity::query()->insert([
            ['did' => 'did:plc:a', 'active' => true, 'updated_at' => $now, 'reconciled_at' => $now, 'reconcile_error' => null],
            ['did' => 'did:plc:b', 'active' => true, 'updated_at' => $now, 'reconciled_at' => $now, 'reconcile_error' => 'cURL error 6: Could not resolve host'],
            ['did' => 'did:plc:c', 'active' => true, 'updated_at' => $now, 'reconciled_at' => $now->copy()->subDays(3), 'reconcile_error' => 'cURL error 6: Could not resolve host'],
            ['did' => 'did:plc:d', 'active' => false, 'updated_at' => $now, 'reconciled_at' => null, 'reconcile_error' => 'cURL error 28: Operation timed out'],
        ]);
        DB::table('atp_identities')->where('did', 'did:plc:d')->update(['status' => 'deactivated']);

        $this->assertSame(['total' => 4, 'active' => 3, 'inactive' => ['deactivated' => 1]], $this->appview()->accounts());

        $reconciliation = $this->appview()->reconciliation();
        $this->assertSame(3, $reconciliation['due']);
        $this->assertSame(2, $reconciliation['checkedRecently']);
        $this->assertSame(3, $reconciliation['errors']);
        $this->assertSame(['Host not found' => 2, 'Timed out' => 1], $reconciliation['errorKinds']);
    }

    public function test_it_reports_lexicons_validation_and_the_blocklist(): void
    {
        config(['atp-appview.lexicons.follow' => [self::POSTS, self::LIKES]]);
        FollowedLexicon::query()->insert(['nsid' => self::POSTS, 'did' => 'did:plc:author', 'uri' => 'at://did:plc:author/com.atproto.lexicon.schema/'.self::POSTS, 'schema' => '{}', 'revision' => 2, 'fetched_at' => now()]);
        FollowedLexicon::query()->insert(['nsid' => self::LIKES, 'did' => 'did:plc:author', 'uri' => 'at://did:plc:author/com.atproto.lexicon.schema/'.self::LIKES, 'schema' => '{}', 'revision' => 1, 'fetched_at' => now(), 'pending_cid' => 'bafy-next', 'pending_schema' => '{}', 'pending_issues' => json_encode([['nsid' => self::LIKES, 'level' => 'error', 'name' => 'removed-field', 'message' => 'subject was removed']])]);
        Block::query()->create(['kind' => 'did', 'subject' => 'did:plc:spam', 'collection' => '*', 'blocked_count' => 7, 'last_blocked_at' => now()]);

        $lexicons = $this->appview()->lexicons();
        $this->assertSame(2, $lexicons['followed']);
        $this->assertSame(1, $lexicons['heldBack']);
        $this->assertTrue(FollowedLexicon::query()->find(self::LIKES)->heldBack());
        $this->assertSame('removed-field', FollowedLexicon::query()->find(self::LIKES)->pending_issues[0]['name']);

        $this->assertSame([self::POSTS => 0, self::LIKES => 0], $this->appview()->validation()['invalid']);

        $blocklist = $this->appview()->blocklist();
        $this->assertSame(1, $blocklist['rules']);
        $this->assertSame(7, $blocklist['dropped']);
        $this->assertNotNull($blocklist['lastDroppedAt']);
    }

    public function test_reconcile_errors_group_by_kind(): void
    {
        $this->assertSame('Host not found', ReconcileErrors::kind('cURL error 6: Could not resolve host: pds.gone'));
        $this->assertSame('Timed out', ReconcileErrors::kind('cURL error 28: Operation timed out'));
        $this->assertSame('Connection refused', ReconcileErrors::kind('cURL error 7: Failed to connect'));
        $this->assertSame('Network error 77', ReconcileErrors::kind('cURL error 77: Problem with the CA cert'));
        $this->assertSame('TLS failure', ReconcileErrors::kind('cURL error 60: SSL certificate problem'));
        $this->assertSame('RepoNotFound', ReconcileErrors::kind('RepoNotFound: Could not find repo'));
        $this->assertSame('Other', ReconcileErrors::kind('something else entirely'));
    }

    public function test_a_reconcile_result_sums_itself_up(): void
    {
        $this->assertSame('In step with its PDS', (new ReconcileResult('did:plc:a', rev: '3l2'))->summary());
        $this->assertSame('2 indexed, 1 tombstoned', (new ReconcileResult('did:plc:a', rev: '3l2', indexed: ['x', 'y'], tombstoned: ['z']))->summary());
        $this->assertSame('Skipped: blocked', (new ReconcileResult('did:plc:a', skipped: 'blocked'))->summary());
        $this->assertSame('Account deleted', (new ReconcileResult('did:plc:a', account: 'deleted'))->summary());
    }
}
