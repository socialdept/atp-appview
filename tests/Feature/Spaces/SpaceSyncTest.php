<?php

namespace SocialDept\AtpAppview\Tests\Feature\Spaces;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Index\Events\RecordIndexed;
use SocialDept\AtpAppview\Index\Events\RecordRemoved;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\IngestHook;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Spaces\Events\SpaceRecordIndexed;
use SocialDept\AtpAppview\Spaces\Events\SpaceRecordRemoved;
use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpAppview\Spaces\SyncSpace;
use SocialDept\AtpAppview\Spaces\SyncSpaceRepo;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSpaces\AtpSpacesServiceProvider;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Client\SpaceReader;
use SocialDept\AtpSpaces\Contracts\CredentialStore;
use SocialDept\AtpSpaces\Events\SpaceDeletedNotified;
use SocialDept\AtpSpaces\Events\SpaceWriteNotified;
use SocialDept\AtpSpaces\Exceptions\SpacesException;
use SocialDept\AtpSpaces\Repo\Commit;
use SocialDept\AtpSpaces\Repo\RepoCommit;
use SocialDept\AtpSpaces\Repo\RepoOp;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Spaces;
use SocialDept\AtpSpaces\SpaceType;
use SocialDept\AtpSpaces\SpaceTypeRegistry;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Syncing followed spaces into atp_space_records: from a repo's operation
 * log, checked against its set hash, from its archive when that fails,
 * stale without a credential, dropped when deleted, and driven by the
 * authority's notifications and appview:spaces:sync. Space records never
 * reach atp_records or the public record events.
 */
class SpaceSyncTest extends TestCase
{
    protected const AUTHORITY = 'did:plc:authority';
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const POST = 'com.example.post';

    // TIDs, in increasing order.
    protected const R1 = '3l2aaaaaaaaaa';
    protected const R2 = '3l2aaaaaaaaab';
    protected const R3 = '3l2aaaaaaaaac';

    protected SpaceReader&MockInterface $reader;

    protected Spaces&MockInterface $spaces;

    protected CredentialStore $store;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpSpacesServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.features', [Features::spaces()]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Queue::fake();
        Event::fake([SpaceRecordIndexed::class, SpaceRecordRemoved::class, RecordIndexed::class, RecordRemoved::class]);

        $this->store = new class () implements CredentialStore {
            /** @var array<string, SpaceCredential> */
            public array $credentials = [];

            public function get(SpaceRef $space, string $userDid): ?SpaceCredential
            {
                return $this->credentials[$space.'|'.$userDid] ?? null;
            }

            public function put(SpaceCredential $credential): void
            {
                $this->credentials[$credential->space.'|'.$credential->userDid] = $credential;
            }

            public function forget(SpaceRef $space, string $userDid): void
            {
                unset($this->credentials[$space.'|'.$userDid]);
            }
        };

        $this->reader = Mockery::mock(SpaceReader::class);
        $this->reader->shouldReceive('registerNotify')->andReturn(CarbonImmutable::now()->addDay())->byDefault();

        $this->spaces = Mockery::mock(Spaces::class);
        $this->spaces->shouldReceive('readerFor')->andReturn($this->reader)->byDefault();
        $this->spaces->shouldReceive('types')->andReturn(new SpaceTypeRegistry())->byDefault();

        $this->app->instance(CredentialStore::class, $this->store);
        $this->app->instance(Spaces::class, $this->spaces);
    }

    protected function space(): SpaceRef
    {
        return SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'main');
    }

    protected function credential(int $expiresIn = 600): SpaceCredential
    {
        return new SpaceCredential(
            $this->space(),
            self::ALICE,
            new SpaceToken(SpaceTokenType::Credential, [], ['exp' => time() + $expiresIn], 'header.payload.signature'),
            CredentialKey::generate(),
        );
    }

    protected function sync(): SpaceSync
    {
        return app(SpaceSync::class);
    }

    protected function uri(string $rkey, string $did = self::BOB): string
    {
        return (string) $this->space()->record($did, self::POST, $rkey);
    }

    /**
     * A commit for Bob's repo holding these records, cid by rkey.
     *
     * @param  array<string, string>  $records
     */
    protected function commit(string $rev, array $records): Commit
    {
        $hash = RepoCommit::fromRecords(array_map(fn ($rkey, $cid) => ['collection' => self::POST, 'rkey' => (string) $rkey, 'cid' => $cid], array_keys($records), $records));

        return new Commit(1, self::BOB, (string) $this->space(), $rev, $hash->digest());
    }

    protected function create(string $rkey, string $cid, string $rev): RepoOp
    {
        return new RepoOp(self::POST, $rkey, $cid, null, $rev, ['$type' => self::POST, 'text' => $rkey, 'createdAt' => '2026-10-21T00:00:00Z']);
    }

    /**
     * The authority listing Bob's repo at this revision, then nothing more.
     */
    protected function listsBob(string $repoRev, string $spaceRev): void
    {
        $this->reader->shouldReceive('listRepos')->with(100, null)->once()->andReturn(['repos' => [['did' => self::BOB, 'repoRev' => $repoRev, 'spaceRev' => $spaceRev]], 'cursor' => $spaceRev]);
        $this->reader->shouldReceive('listRepos')->with(100, $spaceRev)->once()->andReturn(['repos' => [], 'cursor' => null]);
    }

    /**
     * Followed with Alice's credential, synced to Bob's two posts.
     */
    protected function synced(): void
    {
        $this->sync()->follow($this->credential());
        $this->listsBob(self::R1, 'S1');
        $this->reader->shouldReceive('listRepoOps')->with(self::BOB, null, 100, null)->once()->andReturn([
            'ops' => [$this->create('p1', 'c1', self::R1), $this->create('p2', 'c2', self::R1)],
            'commit' => $this->commit(self::R1, ['p1' => 'c1', 'p2' => 'c2']),
            'cursor' => null,
        ]);

        $this->assertTrue($this->sync()->sync($this->space()));
    }

    public function test_following_stores_the_credential_and_queues_a_sync(): void
    {
        $this->sync()->follow($this->credential());

        $row = $this->sync()->state($this->space());
        $this->assertSame(self::ALICE, $row->credential_did);
        $this->assertSame(self::AUTHORITY, $row->authority);
        $this->assertNotNull($this->store->get($this->space(), self::ALICE));
        Queue::assertPushed(SyncSpace::class, fn (SyncSpace $job) => $job->space === (string) $this->space());
    }

    public function test_a_space_type_atp_spaces_does_not_list_cannot_be_followed(): void
    {
        $types = new SpaceTypeRegistry();
        $types->register(SpaceType::fromLexicon('com.example.other', []));
        $this->spaces->shouldReceive('types')->andReturn($types);

        $this->expectException(InvalidArgumentException::class);

        $this->sync()->follow($this->credential());
    }

    public function test_a_sync_writes_the_operation_log_to_space_records_only(): void
    {
        $this->synced();

        $row = DB::table('atp_space_records')->where('uri', $this->uri('p1'))->first();
        $this->assertSame((string) $this->space(), $row->space);
        $this->assertSame(self::BOB, $row->did);
        $this->assertSame('c1', $row->cid);
        $this->assertSame(self::R1, $row->rev);
        $this->assertSame('p1', json_decode($row->record, true)['text']);
        $this->assertSame(2, DB::table('atp_space_records')->count());

        // Never in the public index, never announced as a public record.
        $this->assertSame(0, DB::table('atp_records')->count());
        Event::assertNotDispatched(RecordIndexed::class);
        Event::assertNotDispatched(RecordRemoved::class);
        Event::assertDispatchedTimes(SpaceRecordIndexed::class, 2);
        Event::assertDispatched(SpaceRecordIndexed::class, fn (SpaceRecordIndexed $event) => $event->uri === $this->uri('p1') && $event->space === (string) $this->space());

        $repo = DB::table('atp_space_repos')->where('did', self::BOB)->first();
        $this->assertSame(self::R1, $repo->repo_rev);
        $this->assertSame($this->commit(self::R1, ['p1' => 'c1', 'p2' => 'c2'])->data, RepoCommit::fromState(base64_decode($repo->state))->digest());

        $space = $this->sync()->state($this->space());
        $this->assertSame('S1', $space->space_rev);
        $this->assertNotNull($space->notify_expires_at);
        $this->assertNotNull($space->synced_at);
    }

    public function test_the_public_indexer_leaves_space_records_alone(): void
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(1, self::BOB, self::POST, 'p1', RecordAction::Create, 'c1', self::R1, ['text' => 'private'], true, space: (string) $this->space()),
        ]));

        $this->assertSame(0, DB::table('atp_records')->count());
        Event::assertNotDispatched(RecordIndexed::class);
    }

    public function test_a_space_record_event_carries_its_space_record_uri(): void
    {
        $event = new RecordEvent(1, self::BOB, self::POST, 'p1', RecordAction::Create, 'c1', self::R1, ['text' => 'a'], true, space: (string) $this->space());

        $this->assertSame($this->uri('p1'), $event->uri());
        $this->assertSame($this->uri('p1'), $event->repaired(['text' => 'b'], RejectsP2::class)->uri());
        $this->assertSame('at://'.self::BOB.'/'.self::POST.'/p1', (new RecordEvent(1, self::BOB, self::POST, 'p1', RecordAction::Create, 'c1', self::R1, [], true))->uri());
    }

    public function test_the_notification_routes_are_served(): void
    {
        Route::getRoutes()->refreshNameLookups();

        $this->assertTrue(Route::has('atp-spaces.com.atproto.space.notifyWrite'));
        $this->assertTrue(Route::has('atp-spaces.com.atproto.space.notifySpaceDeleted'));
    }

    public function test_the_next_sync_reads_the_log_from_the_stored_revision(): void
    {
        $this->synced();
        $this->reader->shouldReceive('listRepos')->with(100, 'S1')->andReturn(['repos' => [['did' => self::BOB, 'repoRev' => self::R2, 'spaceRev' => 'S2']], 'cursor' => 'S2']);
        $this->reader->shouldReceive('listRepos')->with(100, 'S2')->andReturn(['repos' => [], 'cursor' => null]);
        $this->reader->shouldReceive('listRepoOps')->with(self::BOB, self::R1, 100, null)->once()->andReturn([
            'ops' => [
                new RepoOp(self::POST, 'p1', 'c1b', 'c1', self::R2, ['$type' => self::POST, 'text' => 'edited']),
                new RepoOp(self::POST, 'p2', null, 'c2', self::R2),
            ],
            'commit' => $this->commit(self::R2, ['p1' => 'c1b']),
            'cursor' => null,
        ]);
        $this->reader->shouldNotReceive('getRepo');

        $this->assertTrue($this->sync()->sync($this->space()));

        $this->assertSame('edited', json_decode(DB::table('atp_space_records')->where('uri', $this->uri('p1'))->value('record'), true)['text']);
        $this->assertNotNull(DB::table('atp_space_records')->where('uri', $this->uri('p2'))->value('deleted_at'));
        Event::assertDispatched(SpaceRecordRemoved::class, fn (SpaceRecordRemoved $event) => $event->uri === $this->uri('p2') && $event->record['text'] === 'p2');
        $this->assertSame('S2', $this->sync()->state($this->space())->space_rev);
    }

    public function test_ops_that_do_not_add_up_to_the_commit_fall_back_to_the_archive(): void
    {
        $this->synced();
        $this->reader->shouldReceive('listRepoOps')->with(self::BOB, self::R1, 100, null)->once()->andReturn([
            'ops' => [$this->create('p3', 'c3', self::R2)],
            // The log left out deleting p1 and p2.
            'commit' => $this->commit(self::R2, ['p3' => 'c3']),
            'cursor' => null,
        ]);
        $this->reader->shouldReceive('getRepo')->with(self::BOB)->once()->andReturn([
            'commit' => $this->commit(self::R2, ['p3' => 'c3']),
            'records' => [['collection' => self::POST, 'rkey' => 'p3', 'cid' => 'c3', 'value' => ['text' => 'p3']]],
        ]);

        $this->assertTrue($this->sync()->syncRepo($this->space(), self::BOB));

        $this->assertNotNull(DB::table('atp_space_records')->where('uri', $this->uri('p1'))->value('deleted_at'));
        $this->assertNotNull(DB::table('atp_space_records')->where('uri', $this->uri('p2'))->value('deleted_at'));
        $this->assertSame('c3', DB::table('atp_space_records')->where('uri', $this->uri('p3'))->value('cid'));
        $repo = DB::table('atp_space_repos')->where('did', self::BOB)->first();
        $this->assertSame(self::R2, $repo->repo_rev);
        $this->assertSame($this->commit(self::R2, ['p3' => 'c3'])->data, RepoCommit::fromState(base64_decode($repo->state))->digest());

        // A replacement from the archive is not announced, as a backfill is not.
        Event::assertDispatchedTimes(SpaceRecordIndexed::class, 2);
        Event::assertNotDispatched(SpaceRecordRemoved::class);
        $this->assertSame(0, DB::table('atp_records')->count());
    }

    public function test_a_log_that_no_longer_reaches_back_falls_back_to_the_archive(): void
    {
        $this->synced();
        $this->reader->shouldReceive('listRepoOps')->with(self::BOB, self::R1, 100, null)->andThrow(new SpacesException('HistoryUnavailable'));
        $this->reader->shouldReceive('getRepo')->with(self::BOB)->once()->andReturn([
            'commit' => $this->commit(self::R2, ['p1' => 'c1', 'p2' => 'c2']),
            'records' => [
                ['collection' => self::POST, 'rkey' => 'p1', 'cid' => 'c1', 'value' => ['text' => 'p1']],
                ['collection' => self::POST, 'rkey' => 'p2', 'cid' => 'c2', 'value' => ['text' => 'p2']],
            ],
        ]);

        $this->assertTrue($this->sync()->syncRepo($this->space(), self::BOB));

        $this->assertSame(self::R2, DB::table('atp_space_repos')->where('did', self::BOB)->value('repo_rev'));
        $this->assertSame(2, DB::table('atp_space_records')->whereNull('deleted_at')->count());
    }

    public function test_a_rejected_record_still_counts_toward_the_hash(): void
    {
        config(['atp-appview.ingest.hooks' => [RejectsP2::class]]);

        $this->synced();

        $this->assertNull(DB::table('atp_space_records')->where('uri', $this->uri('p2'))->first());
        $this->assertSame(self::R1, DB::table('atp_space_repos')->where('did', self::BOB)->value('repo_rev'));
    }

    public function test_a_space_without_a_usable_credential_goes_stale(): void
    {
        $this->sync()->follow($this->credential(expiresIn: 10));
        $this->spaces->shouldNotReceive('readerFor');

        $this->assertFalse($this->sync()->sync($this->space()));

        $row = $this->sync()->state($this->space());
        $this->assertNotNull($row->stale_at);
        $this->assertStringContainsString(self::ALICE, $row->last_error);

        // A credential stored later lets the next sync through.
        $this->store->put($this->credential());
        $this->spaces->shouldReceive('readerFor')->andReturn($this->reader);
        $this->listsBob(self::R1, 'S1');
        $this->reader->shouldReceive('listRepoOps')->andReturn(['ops' => [], 'commit' => $this->commit(self::R1, []), 'cursor' => null]);

        $this->assertTrue($this->sync()->sync($this->space()));
        $this->assertNull($this->sync()->state($this->space())->stale_at);
    }

    public function test_a_deleted_space_is_dropped(): void
    {
        $this->synced();

        event(new SpaceDeletedNotified($this->space()));

        $this->assertSame(2, DB::table('atp_space_records')->whereNotNull('deleted_at')->whereNull('record')->count());
        $this->assertSame(0, DB::table('atp_space_repos')->count());
        $this->assertNotNull($this->sync()->state($this->space())->deleted_at);
        $this->assertFalse($this->sync()->follows($this->space()));
        $this->assertFalse($this->sync()->sync($this->space()));
    }

    public function test_unfollowing_unregisters_and_drops_the_copy(): void
    {
        $this->synced();
        $this->reader->shouldReceive('unregisterNotify')->once();

        $this->sync()->unfollow($this->space());

        $this->assertNull($this->sync()->state($this->space()));
        $this->assertSame(0, DB::table('atp_space_records')->count());
        $this->assertSame(0, DB::table('atp_space_repos')->count());
    }

    public function test_a_write_that_follows_the_stored_space_rev_syncs_only_its_repo(): void
    {
        $this->synced();

        event(new SpaceWriteNotified($this->space(), self::BOB, self::R2, 'data', 'S2', 'S1'));

        Queue::assertPushed(SyncSpaceRepo::class, fn (SyncSpaceRepo $job) => $job->did === self::BOB && $job->spaceRev === 'S2' && $job->prevSpaceRev === 'S1');
    }

    public function test_a_write_after_a_gap_syncs_the_whole_space(): void
    {
        DB::table('atp_spaces')->insert(['space' => (string) $this->space(), 'authority' => self::AUTHORITY, 'type' => 'com.example.forum', 'skey' => 'main', 'space_rev' => 'S1']);

        event(new SpaceWriteNotified($this->space(), self::BOB, self::R2, 'data', 'S3', 'S2'));

        Queue::assertPushed(SyncSpace::class);
        Queue::assertNotPushed(SyncSpaceRepo::class);
    }

    public function test_a_write_to_a_space_not_followed_is_ignored(): void
    {
        event(new SpaceWriteNotified($this->space(), self::BOB, self::R2, 'data', 'S1'));

        Queue::assertNothingPushed();
    }

    public function test_a_repo_sync_moves_the_space_rev_on_when_it_still_follows(): void
    {
        $this->synced();
        $this->reader->shouldReceive('listRepoOps')->with(self::BOB, self::R1, 100, null)->andReturn([
            'ops' => [$this->create('p3', 'c3', self::R2)],
            'commit' => $this->commit(self::R2, ['p1' => 'c1', 'p2' => 'c2', 'p3' => 'c3']),
            'cursor' => null,
        ]);

        (new SyncSpaceRepo((string) $this->space(), self::BOB, 'S2', 'S1'))->handle($this->sync());
        $this->assertSame('S2', $this->sync()->state($this->space())->space_rev);
        $this->reader->shouldReceive('listRepoOps')->with(self::BOB, self::R2, 100, null)->andReturn([
            'ops' => [],
            'commit' => $this->commit(self::R2, ['p1' => 'c1', 'p2' => 'c2', 'p3' => 'c3']),
            'cursor' => null,
        ]);

        // Out of order: the stored spaceRev is no longer the one it follows.
        (new SyncSpaceRepo((string) $this->space(), self::BOB, 'S4', 'S3'))->handle($this->sync());
        $this->assertSame('S2', $this->sync()->state($this->space())->space_rev);
    }

    public function test_the_command_syncs_every_followed_space(): void
    {
        $this->sync()->follow($this->credential());
        $this->listsBob(self::R1, 'S1');
        $this->reader->shouldReceive('listRepoOps')->andReturn(['ops' => [$this->create('p1', 'c1', self::R1)], 'commit' => $this->commit(self::R1, ['p1' => 'c1']), 'cursor' => null]);

        $this->artisan('appview:spaces:sync')
            ->expectsOutputToContain('Synced 1 spaces, 0 waiting for a credential or not followed, 0 failed.')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('atp_space_records')->count());
    }

    public function test_the_command_renews_a_registration_close_to_lapsing(): void
    {
        $this->synced();
        DB::table('atp_spaces')->update(['notify_expires_at' => now()->addHour()->format('Y-m-d H:i:s')]);
        $this->reader->shouldReceive('registerNotify')->once()->andReturn(CarbonImmutable::now()->addDay());
        $this->reader->shouldReceive('listRepos')->with(100, 'S1')->andReturn(['repos' => [], 'cursor' => null]);

        $this->artisan('appview:spaces:sync', ['spaces' => [(string) $this->space()]])->assertSuccessful();

        $this->assertTrue(now()->addHours(20)->lessThan($this->sync()->state($this->space())->notify_expires_at));
    }

    public function test_the_command_can_queue_its_syncs(): void
    {
        DB::table('atp_spaces')->insert(['space' => (string) $this->space(), 'authority' => self::AUTHORITY, 'type' => 'com.example.forum', 'skey' => 'main']);

        $this->artisan('appview:spaces:sync', ['--queue' => true])->assertSuccessful();

        Queue::assertPushed(SyncSpace::class, fn (SyncSpace $job) => $job->space === (string) $this->space());
    }

    public function test_the_command_reports_a_failed_sync(): void
    {
        $this->sync()->follow($this->credential());
        $this->reader->shouldReceive('listRepos')->andThrow(new SpacesException('down'));

        $this->artisan('appview:spaces:sync')->assertFailed();

        $this->assertSame('down', $this->sync()->state($this->space())->last_error);
    }
}

class RejectsP2 implements IngestHook
{
    public function reject(array $records): array
    {
        $reasons = [];

        foreach ($records as $event) {
            if ($event->rkey === 'p2') {
                $reasons[$event->uri()] = 'not wanted';
            }
        }

        return $reasons;
    }
}
