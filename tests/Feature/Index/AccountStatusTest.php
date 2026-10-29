<?php

namespace SocialDept\AtpAppview\Tests\Feature\Index;

use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Index\Reconciler;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpClient\Testing\FakeAtpManager;
use SocialDept\AtpClient\Testing\FakeResponse;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use SocialDept\AtpSupport\Facades\Resolver;

class AccountStatusTest extends TestCase
{
    protected const DID = 'did:plc:alice';
    protected const POSTS = 'com.example.post';
    protected const REV = '3l2aaaaaaaaaa';
    protected const NOW = '3l2aaaaaaaaab';

    protected FakeAtpManager $atp;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('atp-appview.ingest.collections', [self::POSTS]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

        $this->atp = Atp::fake();
        $this->atp->stub('com.atproto.sync.getLatestCommit', ['cid' => 'c', 'rev' => self::NOW]);
        $this->atp->stub('com.atproto.repo.describeRepo', ['handle' => 'alice.test', 'did' => self::DID, 'didDoc' => [], 'collections' => [self::POSTS], 'handleIsCorrect' => true]);
        $this->atp->stub('com.atproto.repo.listRecords', ['records' => [
            ['uri' => 'at://did:plc:alice/com.example.post/a', 'cid' => 'cid-a', 'value' => ['text' => 'a']],
            ['uri' => 'at://did:plc:alice/com.example.post/b', 'cid' => 'cid-b', 'value' => ['text' => 'b']],
        ]]);

        $this->ingest(new IdentityEvent(0, self::DID, 'alice.test', true, 'active'));
        $this->ingest($this->postEvent('a'), $this->postEvent('b'));
    }

    protected function ingest(RecordEvent|IdentityEvent ...$events): void
    {
        app(RecordIndexer::class)->handle(new Batch($events));
    }

    protected function postEvent(string $rkey): RecordEvent
    {
        return new RecordEvent(0, self::DID, self::POSTS, $rkey, RecordAction::Create, "cid-{$rkey}", self::REV, ['text' => $rkey], true);
    }

    protected function identity(): Identity
    {
        return Identity::query()->findOrFail(self::DID);
    }

    public function test_a_deleted_account_has_its_records_tombstoned(): void
    {
        $this->ingest(new IdentityEvent(0, self::DID, 'alice.test', false, 'deleted'));

        $this->assertSame(0, Record::withHidden()->count());
        $tombstone = Record::withTrashed()->find('at://did:plc:alice/com.example.post/a');
        $this->assertNotNull($tombstone->deleted_at);
        $this->assertNull($tombstone->record);

        // Each record keeps its own revision, so a newer listing can restore
        // it if the account turns out to exist after all.
        $this->assertSame(self::REV, $tombstone->rev);
        $this->assertNotNull($this->identity()->inactive_since);
    }

    public function test_a_deactivated_account_has_its_records_hidden_until_it_returns(): void
    {
        $this->ingest(new IdentityEvent(0, self::DID, 'alice.test', false, 'deactivated'));

        $this->assertSame(0, Record::count());
        $this->assertSame(2, Record::withHidden()->count());
        $this->assertSame(2, Record::onlyHidden()->count());
        $this->assertNotNull($this->identity()->inactive_since);

        // Anything Tap delivers late for the account stays hidden too.
        $this->ingest($this->postEvent('late'));
        $this->assertSame(0, Record::count());

        $this->ingest(new IdentityEvent(0, self::DID, 'alice.test', true, 'active'));

        $this->assertSame(3, Record::count());
        $this->assertNull($this->identity()->inactive_since);
    }

    public function test_a_takedown_hides_records_like_a_deactivation(): void
    {
        $this->ingest(new IdentityEvent(0, self::DID, 'alice.test', false, 'takendown'));

        $this->assertSame(0, Record::count());
        $this->assertSame(2, Record::withHidden()->count());
    }

    public function test_a_repo_its_pds_cannot_find_is_deleted_after_resolving_again(): void
    {
        Resolver::shouldReceive('resolvePds')->with(self::DID, true)->once()->andReturn('https://old.example');
        Resolver::shouldReceive('resolvePds')->with(self::DID, false)->once()->andReturn('https://old.example');
        $this->atp->stub('com.atproto.sync.getLatestCommit', FakeResponse::error('RepoNotFound', 'Could not find repo', 400));

        $result = app(Reconciler::class)->reconcile(self::DID);

        $this->assertSame('deleted', $result->account);
        $this->assertSame(0, Record::withHidden()->count());
        $this->assertFalse($this->identity()->active);
        $this->assertSame('deleted', $this->identity()->status);
    }

    public function test_a_repo_that_moved_is_read_from_its_new_pds(): void
    {
        Resolver::shouldReceive('resolvePds')->with(self::DID, true)->andReturn('https://old.example');
        Resolver::shouldReceive('resolvePds')->with(self::DID, false)->andReturn('https://new.example');
        $this->atp->sequence('com.atproto.sync.getLatestCommit')
            ->push(FakeResponse::error('RepoNotFound', 'Could not find repo', 400))
            ->push(['cid' => 'c', 'rev' => self::NOW]);

        $result = app(Reconciler::class)->reconcile(self::DID);

        $this->assertNull($result->account);
        $this->assertSame(self::NOW, $result->rev);
        $this->assertTrue($this->identity()->active);
        $this->assertSame(2, Record::count());
    }

    public function test_a_deactivated_repo_is_hidden_and_shown_again_when_its_pds_serves_it(): void
    {
        Resolver::shouldReceive('resolvePds')->andReturn('https://pds.example');
        $this->atp->stub('com.atproto.sync.getLatestCommit', FakeResponse::error('RepoDeactivated', 'Repo has been deactivated', 400));

        $this->assertSame('deactivated', app(Reconciler::class)->reconcile(self::DID)->account);
        $this->assertSame(0, Record::count());
        $this->assertSame('deactivated', $this->identity()->status);

        $this->atp->stub('com.atproto.sync.getLatestCommit', ['cid' => 'c', 'rev' => self::NOW]);

        $this->assertSame('reactivated', app(Reconciler::class)->reconcile(self::DID)->account);
        $this->assertSame(2, Record::count());
        $this->assertTrue($this->identity()->active);
        $this->assertSame('active', $this->identity()->status);
    }

    public function test_reconciling_leaves_a_takedown_to_tap(): void
    {
        $this->ingest(new IdentityEvent(0, self::DID, 'alice.test', false, 'takendown'));

        $result = app(Reconciler::class)->reconcile(self::DID);

        $this->assertSame('takendown', $result->skipped);
        $this->atp->assertNothingCalled();
        $this->assertSame(0, Record::count());
    }

    public function test_a_dry_run_changes_no_account(): void
    {
        Resolver::shouldReceive('resolvePds')->andReturn('https://pds.example');
        $this->atp->stub('com.atproto.sync.getLatestCommit', FakeResponse::error('RepoDeactivated', 'Repo has been deactivated', 400));

        $this->assertSame('deactivated', app(Reconciler::class)->reconcile(self::DID, dryRun: true)->account);
        $this->assertTrue($this->identity()->active);
        $this->assertSame(2, Record::count());
    }

    public function test_records_of_accounts_inactive_too_long_are_purged_and_restored_if_they_return(): void
    {
        Identity::query()->insert(['did' => 'did:plc:recent', 'handle' => null, 'active' => false, 'status' => 'deactivated', 'updated_at' => now(), 'inactive_since' => now()->subDays(10)]);
        app(RecordIndexer::class)->handle(new Batch([new RecordEvent(0, 'did:plc:recent', self::POSTS, 'r', RecordAction::Create, 'cid-r', self::REV, ['text' => 'r'], true)]));
        $this->ingest(new IdentityEvent(0, self::DID, 'alice.test', false, 'deactivated'));
        Identity::query()->whereKey(self::DID)->update(['inactive_since' => now()->subDays(200)]);

        $this->artisan('appview:purge-inactive', ['--dry-run' => true])->expectsOutputToContain('2 records would be tombstoned')->assertSuccessful();
        $this->assertSame(2, Record::onlyHidden()->where('did', self::DID)->count());

        $this->artisan('appview:purge-inactive')->expectsOutputToContain('1 accounts inactive for over 180 days, 2 records tombstoned')->assertSuccessful();

        $this->assertSame(0, Record::withHidden()->where('did', self::DID)->count());
        $this->assertSame(1, Record::onlyHidden()->where('did', 'did:plc:recent')->count());

        // Nothing left to purge.
        $this->artisan('appview:purge-inactive')->expectsOutputToContain('0 accounts')->assertSuccessful();

        // The account comes back: reconciling restores its records.
        Resolver::shouldReceive('resolvePds')->andReturn('https://pds.example');
        $result = app(Reconciler::class)->reconcile(self::DID);

        $this->assertSame('reactivated', $result->account);
        $this->assertCount(2, $result->indexed);
        $this->assertSame(2, Record::where('did', self::DID)->count());
    }

    public function test_upgrading_applies_the_status_of_accounts_already_inactive(): void
    {
        // Back to before the account status migration, however many follow it.
        // Features keep theirs in folders of their own, run alongside, and
        // only those of the features turned on have run.
        $step = DB::table('migrations')->where('migration', '>=', '2026_10_17_000008_add_account_status_to_atp_records_and_identities')->count();
        $this->artisan('migrate:rollback', ['--step' => $step])->run();
        Identity::query()->insert([
            ['did' => 'did:plc:gone', 'handle' => null, 'active' => false, 'status' => 'deleted', 'updated_at' => '2026-01-01 00:00:00'],
            ['did' => 'did:plc:away', 'handle' => null, 'active' => false, 'status' => 'deactivated', 'updated_at' => '2026-02-01 00:00:00'],
        ]);
        // Written as the previous version did, before the new columns.
        foreach (['gone', 'away'] as $name) {
            app('db')->connection()->table('atp_records')->insert([[
                'uri' => "at://did:plc:{$name}/com.example.post/r", 'did' => "did:plc:{$name}", 'collection' => self::POSTS, 'rkey' => 'r',
                'cid' => 'cid-r', 'record' => '{"text":"r"}', 'indexed_at' => '2026-01-01 00:00:00', 'sort_at' => '2026-01-01 00:00:00', 'deleted_at' => null, 'rev' => self::REV,
            ]]);
        }

        $this->artisan('migrate')->run();

        $this->assertNotNull(Record::withTrashed()->find('at://did:plc:gone/com.example.post/r')->deleted_at);
        $this->assertSame(self::REV, Record::withTrashed()->find('at://did:plc:gone/com.example.post/r')->rev);
        $this->assertSame(1, Record::onlyHidden()->where('did', 'did:plc:away')->count());
        $this->assertSame('2026-02-01 00:00:00', Identity::find('did:plc:away')->inactive_since->format('Y-m-d H:i:s'));
        $this->assertSame(2, Record::count());
    }

    public function test_reconcile_checks_deactivated_repos_but_not_takedowns(): void
    {
        Resolver::shouldReceive('resolvePds')->andReturn('https://pds.example');
        Identity::query()->insert([
            ['did' => 'did:plc:away', 'handle' => null, 'active' => false, 'status' => 'deactivated', 'updated_at' => now()],
            ['did' => 'did:plc:banned', 'handle' => null, 'active' => false, 'status' => 'takendown', 'updated_at' => now()],
        ]);

        $this->artisan('appview:reconcile', ['--dry-run' => true])
            ->expectsOutputToContain('did:plc:away')
            ->doesntExpectOutputToContain('did:plc:banned')
            ->assertSuccessful();
    }
}
