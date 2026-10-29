<?php

namespace SocialDept\AtpAppview\Tests\Feature\Index;

use Illuminate\Support\Carbon;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Index\Reconciler;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\IngestHook;
use SocialDept\AtpAppview\Ingest\Contracts\IngestRepair;
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

class ReconcilerTest extends TestCase
{
    protected const DID = 'did:plc:alice';
    protected const POSTS = 'com.example.post';
    protected const LIKES = 'com.example.like';

    // TIDs, in increasing order. The PDS is at NOW.
    protected const BEFORE = '3l2aaaaaaaaaa';
    protected const NOW = '3l2aaaaaaaaab';
    protected const AFTER = '3l2aaaaaaaaac';

    protected FakeAtpManager $atp;

    /**
     * What the PDS holds, by collection then rkey.
     *
     * @var array<string, array<string, array{cid: string, value: array<string, mixed>}>>
     */
    protected array $pds = [];

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('atp-appview.ingest.collections', [self::POSTS, self::LIKES]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

        Resolver::shouldReceive('resolvePds')->andReturn('https://pds.example');
        Identity::query()->insert(['did' => self::DID, 'handle' => 'alice.test', 'active' => true, 'updated_at' => now(), 'pds' => 'https://pds.example', 'pds_resolved_at' => now()]);

        $this->atp = Atp::fake();
        $this->atp->stub('com.atproto.sync.getLatestCommit', ['cid' => 'c', 'rev' => self::NOW]);
        $this->atp->stub('com.atproto.repo.listRecords', fn ($endpoint, $params) => $this->page($params));
        $this->atp->stub('com.atproto.repo.describeRepo', fn () => ['handle' => 'alice.test', 'did' => self::DID, 'didDoc' => [], 'collections' => array_keys(array_filter($this->pds)), 'handleIsCorrect' => true]);
    }

    /**
     * The PDS pages its listing two records at a time, so every test pages.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function page(array $params): array
    {
        $records = $this->pds[$params['collection']] ?? [];
        $offset = (int) ($params['cursor'] ?? 0);
        $slice = array_slice($records, $offset, 2, true);

        return [
            'records' => array_map(
                fn (string $rkey, array $record) => ['uri' => 'at://'.self::DID."/{$params['collection']}/{$rkey}", ...$record],
                array_keys($slice),
                $slice,
            ),
            'cursor' => $offset + 2 < count($records) ? (string) ($offset + 2) : null,
        ];
    }

    protected function onPds(string $collection, string $rkey, string $cid, array $value = ['text' => 'hi']): void
    {
        $this->pds[$collection][$rkey] = ['cid' => $cid, 'value' => $value];
    }

    protected function indexed(string $collection, string $rkey, string $cid, string $rev, RecordAction $action = RecordAction::Create): void
    {
        app(RecordIndexer::class)->handle(new Batch([new RecordEvent(0, self::DID, $collection, $rkey, $action, $action === RecordAction::Delete ? null : $cid, $rev, $action === RecordAction::Delete ? null : ['text' => 'old'], true)]));
    }

    protected function record(string $collection, string $rkey): ?Record
    {
        return Record::withTrashed()->find('at://'.self::DID."/{$collection}/{$rkey}");
    }

    public function test_a_reconcile_goes_through_the_ingest_hooks_and_repairs(): void
    {
        config([
            'atp-appview.ingest.hooks' => [RejectSpam::class],
            'atp-appview.ingest.repairs' => [TrimText::class],
        ]);
        $this->onPds(self::POSTS, 'spam', 'cid-spam', ['text' => 'buy now']);
        $this->onPds(self::POSTS, 'fine', 'cid-fine', ['text' => '  hello  ']);

        app(Reconciler::class)->reconcile(self::DID);

        $this->assertNull($this->record(self::POSTS, 'spam'), 'A record a hook rejects is not indexed by a reconcile either.');
        $this->assertSame('hello', $this->record(self::POSTS, 'fine')->record['text'], 'A repair rewrites a reconciled record, as it would one from Tap.');
    }

    public function test_a_record_the_pds_no_longer_has_is_tombstoned(): void
    {
        foreach (['a', 'b', 'c'] as $rkey) {
            $this->onPds(self::POSTS, $rkey, "cid-{$rkey}");
            $this->indexed(self::POSTS, $rkey, "cid-{$rkey}", self::BEFORE);
        }
        $this->indexed(self::POSTS, 'gone', 'cid-gone', self::BEFORE);

        $result = app(Reconciler::class)->reconcile(self::DID);

        $this->assertSame(['at://did:plc:alice/com.example.post/gone'], $result->tombstoned);
        $this->assertSame([], $result->indexed);
        $this->assertSame(3, $result->listed);

        $gone = $this->record(self::POSTS, 'gone');
        $this->assertNotNull($gone->deleted_at);
        $this->assertNull($gone->record);
        $this->assertSame(self::NOW, $gone->rev);
        $this->assertNull($this->record(self::POSTS, 'a')->deleted_at);
    }

    public function test_a_record_the_index_missed_or_holds_at_another_cid_is_indexed(): void
    {
        $this->onPds(self::POSTS, 'new', 'cid-new', ['text' => 'missed']);
        $this->onPds(self::POSTS, 'edited', 'cid-v2', ['text' => 'v2']);
        $this->onPds(self::LIKES, 'back', 'cid-back');
        $this->indexed(self::POSTS, 'edited', 'cid-v1', self::BEFORE);
        $this->indexed(self::LIKES, 'back', 'cid-back', self::BEFORE, RecordAction::Delete);

        $result = app(Reconciler::class)->reconcile(self::DID);

        $this->assertEqualsCanonicalizing([
            'at://did:plc:alice/com.example.post/new',
            'at://did:plc:alice/com.example.post/edited',
            'at://did:plc:alice/com.example.like/back',
        ], $result->indexed);
        $this->assertSame(['text' => 'missed'], $this->record(self::POSTS, 'new')->record);
        $this->assertSame('cid-v2', $this->record(self::POSTS, 'edited')->cid);
        $this->assertNull($this->record(self::LIKES, 'back')->deleted_at);
    }

    public function test_only_the_collections_asked_for_are_reconciled_ingested_or_not(): void
    {
        $this->onPds(self::POSTS, 'missed', 'cid-missed');
        $this->onPds('com.example.follow', 'f1', 'cid-f1', ['subject' => 'did:plc:bob']);
        $this->indexed('com.example.follow', 'gone', 'cid-gone', self::BEFORE);

        $result = app(Reconciler::class)->reconcile(self::DID, collections: ['com.example.follow']);

        $this->assertSame(['at://did:plc:alice/com.example.follow/f1'], $result->indexed);
        $this->assertSame(['at://did:plc:alice/com.example.follow/gone'], $result->tombstoned);
        $this->assertNull($this->record(self::POSTS, 'missed'), 'Collections not asked for are left alone.');
    }

    public function test_a_collection_the_repo_no_longer_has_is_emptied_without_listing_it(): void
    {
        $this->onPds(self::POSTS, 'a', 'cid-a');
        $this->indexed(self::POSTS, 'a', 'cid-a', self::BEFORE);
        $this->indexed(self::LIKES, 'old', 'cid-old', self::BEFORE);

        $result = app(Reconciler::class)->reconcile(self::DID);

        $this->assertSame(['at://did:plc:alice/com.example.like/old'], $result->tombstoned);
        $this->assertSame([self::POSTS], array_map(fn ($request) => $request->params['collection'], $this->atp->getRecordedFor('com.atproto.repo.listRecords')));
    }

    public function test_a_repo_in_step_changes_nothing(): void
    {
        $this->onPds(self::POSTS, 'a', 'cid-a');
        $this->indexed(self::POSTS, 'a', 'cid-a', self::BEFORE);
        $indexedAt = $this->record(self::POSTS, 'a')->getRawOriginal('indexed_at');

        Carbon::setTestNow(now()->addMinute());
        $result = app(Reconciler::class)->reconcile(self::DID);

        $this->assertFalse($result->changed());
        $this->assertSame($indexedAt, $this->record(self::POSTS, 'a')->getRawOriginal('indexed_at'));
        $this->assertSame(self::BEFORE, $this->record(self::POSTS, 'a')->rev);
    }

    public function test_nothing_newer_than_the_listing_is_touched(): void
    {
        // Created after the revision the listing started from, so not in it.
        $this->indexed(self::POSTS, 'fresh', 'cid-fresh', self::AFTER);

        // Deleted after it, though the listing still had it.
        $this->onPds(self::POSTS, 'deleted', 'cid-deleted');
        $this->indexed(self::POSTS, 'deleted', 'cid-deleted', self::AFTER, RecordAction::Delete);

        $result = app(Reconciler::class)->reconcile(self::DID);

        $this->assertFalse($result->changed());
        $this->assertNull($this->record(self::POSTS, 'fresh')->deleted_at);
        $this->assertNotNull($this->record(self::POSTS, 'deleted')->deleted_at);
    }

    public function test_a_failed_listing_writes_nothing(): void
    {
        $this->onPds(self::POSTS, 'b', 'cid-b');
        $this->indexed(self::POSTS, 'a', 'cid-a', self::BEFORE);
        $this->atp->stub('com.atproto.repo.listRecords', FakeResponse::error('InternalServerError', 'down', 500));

        try {
            app(Reconciler::class)->reconcile(self::DID);
            $this->fail('A failed listing should throw.');
        } catch (\Throwable) {
        }

        $this->assertNull($this->record(self::POSTS, 'a')->deleted_at);
    }

    public function test_a_collection_past_the_limit_writes_nothing(): void
    {
        config()->set('atp-appview.index.reconcile.max_records', 2);
        foreach (['a', 'b', 'c'] as $rkey) {
            $this->onPds(self::POSTS, $rkey, "cid-{$rkey}");
        }
        $this->indexed(self::POSTS, 'gone', 'cid-gone', self::BEFORE);

        $this->expectExceptionMessage('holds more than 2 records');

        try {
            app(Reconciler::class)->reconcile(self::DID);
        } finally {
            $this->assertNull($this->record(self::POSTS, 'gone')->deleted_at);
            $this->assertNull($this->record(self::POSTS, 'a'));
        }
    }

    public function test_a_record_listed_under_another_repo_is_refused(): void
    {
        $this->onPds(self::POSTS, 'a', 'cid-a');
        $this->atp->stub('com.atproto.repo.listRecords', ['records' => [['uri' => 'at://did:plc:bob/com.example.post/a', 'cid' => 'x', 'value' => []]]]);

        $this->expectExceptionMessage('not in did:plc:alice');

        app(Reconciler::class)->reconcile(self::DID);
    }

    public function test_blocked_repos_and_collections_are_left_alone(): void
    {
        $this->onPds(self::POSTS, 'kept', 'cid-kept');
        $this->indexed(self::POSTS, 'gone', 'cid-gone', self::BEFORE);
        app(Blocklist::class)->block(self::DID, self::POSTS);

        $result = app(Reconciler::class)->reconcile(self::DID);

        $this->assertNull($this->record(self::POSTS, 'gone')->deleted_at);
        $this->atp->assertNotCalled('com.atproto.repo.listRecords');
        $this->assertSame(0, $result->listed);

        app(Blocklist::class)->block(self::DID);

        $this->assertSame('blocked', app(Reconciler::class)->reconcile(self::DID)->skipped);
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $this->indexed(self::POSTS, 'gone', 'cid-gone', self::BEFORE);

        $result = app(Reconciler::class)->reconcile(self::DID, dryRun: true);

        $this->assertSame(['at://did:plc:alice/com.example.post/gone'], $result->tombstoned);
        $this->assertNull($this->record(self::POSTS, 'gone')->deleted_at);
    }

    public function test_the_command_works_through_due_repos_and_remembers_the_outcome(): void
    {
        Identity::query()->insert([
            ['did' => 'did:plc:recent', 'handle' => null, 'active' => true, 'updated_at' => now(), 'reconciled_at' => now()->subHour()],
            ['did' => 'did:plc:inactive', 'handle' => null, 'active' => false, 'updated_at' => now(), 'reconciled_at' => null],
        ]);
        $this->indexed(self::POSTS, 'gone', 'cid-gone', self::BEFORE);

        $this->artisan('appview:reconcile')
            ->expectsOutputToContain('0 indexed, 1 tombstoned')
            ->assertSuccessful();

        $alice = Identity::find(self::DID);
        $this->assertNotNull($alice->reconciled_at);
        $this->assertSame(self::NOW, $alice->reconciled_rev);
        $this->assertNull($alice->reconcile_error);
        $this->assertNull(Identity::find('did:plc:inactive')->reconciled_at);
        $this->assertTrue(Identity::find('did:plc:recent')->reconciled_at->lt(now()->subMinutes(30)));

        // Checked, so not due again until the interval passes.
        $this->artisan('appview:reconcile')->expectsOutputToContain('0 repos')->assertSuccessful();
    }

    public function test_the_command_records_a_failure_and_moves_on(): void
    {
        $this->atp->stub('com.atproto.sync.getLatestCommit', FakeResponse::error('InternalServerError', 'The PDS fell over', 500));

        $this->artisan('appview:reconcile', ['did' => [self::DID]])->expectsOutputToContain('1 failed')->assertSuccessful();

        $alice = Identity::find(self::DID);
        $this->assertNotNull($alice->reconciled_at);
        $this->assertStringContainsString('The PDS fell over', $alice->reconcile_error);
        $this->assertTrue($alice->active);
    }

    public function test_a_dry_run_command_remembers_nothing(): void
    {
        $this->indexed(self::POSTS, 'gone', 'cid-gone', self::BEFORE);

        $this->artisan('appview:reconcile', ['--dry-run' => true])->expectsOutputToContain('Dry run.')->assertSuccessful();

        $this->assertNull(Identity::find(self::DID)->reconciled_at);
        $this->assertNull($this->record(self::POSTS, 'gone')->deleted_at);
    }
}

class RejectSpam implements IngestHook
{
    public function reject(array $records): array
    {
        $rejected = [];

        foreach ($records as $record) {
            if (str_contains($record->record['text'] ?? '', 'buy now')) {
                $rejected[$record->uri()] = 'spam';
            }
        }

        return $rejected;
    }
}

class TrimText implements IngestRepair
{
    public function repair(RecordEvent $record): ?array
    {
        $text = $record->record['text'] ?? null;

        return is_string($text) && $text !== trim($text) ? ['text' => trim($text)] + $record->record : null;
    }
}
