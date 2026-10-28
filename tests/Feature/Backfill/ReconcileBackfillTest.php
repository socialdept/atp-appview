<?php

namespace SocialDept\AtpAppview\Tests\Feature\Backfill;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Backfill\Backfills;
use SocialDept\AtpAppview\Backfill\Events\BackfillFinished;
use SocialDept\AtpAppview\Backfill\ReconcileRepos;
use SocialDept\AtpAppview\Models\Backfill;
use SocialDept\AtpAppview\Models\BackfillRepo;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpClient\Testing\FakeAtpManager;
use SocialDept\AtpClient\Testing\FakeResponse;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Facades\Resolver;

/**
 * Backfills without Tap: with Jetstream as the source, each discovered repo
 * is read from its PDS in queued jobs, which run inline here.
 */
class ReconcileBackfillTest extends TestCase
{
    protected const RELAY = 'https://relay.test';
    protected const POSTS = 'app.example.post';
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';

    protected FakeAtpManager $atp;

    /** @var array<string, int> listRecords calls by repo */
    protected array $listed = [];

    /**
     * Records each repo holds in the collection.
     *
     * @var array<string, array<string, array{cid: string, value: array<string, mixed>}>>
     */
    protected array $pds = [
        self::ALICE => ['a' => ['cid' => 'cid-a', 'value' => ['text' => 'one']], 'b' => ['cid' => 'cid-b', 'value' => ['text' => 'two']]],
        self::BOB => [],
    ];

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('atp-appview.ingest.source', 'jetstream');
        $app['config']->set('atp-appview.ingest.discovery_url', self::RELAY);
        $app['config']->set('atp-appview.ingest.collections', [self::POSTS]);
        $app['config']->set('atp-appview.ingest.admin_url', 'http://tap.test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();

        Http::fake([self::RELAY.'/*' => Http::response(['repos' => [['did' => self::ALICE], ['did' => self::BOB], ['did' => self::CAROL]]])]);
        Resolver::shouldReceive('resolvePds')->andReturn('https://pds.example');
        Resolver::shouldReceive('resolveDid')->andReturnUsing(fn (string $did) => DidDocument::fromArray([
            'id' => $did,
            'alsoKnownAs' => ['at://'.substr($did, 8).'.test'],
            'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example']],
        ]));
        Resolver::shouldReceive('handleToDid')->andReturnUsing(fn (string $handle) => 'did:plc:'.substr($handle, 0, -5));

        $this->atp = Atp::fake();
        $this->atp->stub('com.atproto.sync.getLatestCommit', fn ($endpoint, $params) => ($params['did'] ?? null) === self::CAROL
            ? FakeResponse::error('RepoTakendown', 'Repo has been takendown', 400)
            : ['cid' => 'c', 'rev' => '3l2aaaaaaaaab']);
        $this->atp->stub('com.atproto.repo.describeRepo', fn ($endpoint, $params) => ['handle' => 'x.test', 'did' => $params['repo'], 'didDoc' => [], 'collections' => $this->pds[$params['repo']] === [] ? [] : [self::POSTS], 'handleIsCorrect' => true]);
        $this->atp->stub('com.atproto.repo.listRecords', function ($endpoint, $params) {
            $this->listed[$params['repo']] = ($this->listed[$params['repo']] ?? 0) + 1;

            return ['records' => array_map(
                fn (string $rkey, array $record) => ['uri' => "at://{$params['repo']}/".self::POSTS."/{$rkey}", ...$record],
                array_keys($this->pds[$params['repo']]),
                $this->pds[$params['repo']],
            )];
        });
    }

    protected function repo(Backfill $backfill, string $did): BackfillRepo
    {
        return BackfillRepo::query()->where('backfill_id', $backfill->id)->where('did', $did)->sole();
    }

    public function test_with_jetstream_a_backfill_reads_each_repo_from_its_pds_without_tap(): void
    {
        Event::fake([BackfillFinished::class]);

        $backfill = app(Backfills::class)->start(self::POSTS)->refresh();

        $this->assertSame(Backfill::FINISHED, $backfill->status, 'The last job to finish ends the backfill.');
        Event::assertDispatchedTimes(BackfillFinished::class, 1);

        $this->assertSame(['one', 'two'], Record::query()->where('did', self::ALICE)->orderBy('rkey')->get()->map(fn (Record $r) => $r->record['text'])->all());
        $this->assertSame(2, $this->repo($backfill, self::ALICE)->records, 'BackfillTracker counts the records as they go through the pipeline.');
        $this->assertNotNull($this->repo($backfill, self::BOB)->delivered_at, 'A repo with nothing in the collection is still read.');
        $this->assertSame(0, $this->repo($backfill, self::BOB)->records);
        $this->assertSame('Account takendown', $this->repo($backfill, self::CAROL)->error);
        $this->assertNull($this->repo($backfill, self::CAROL)->delivered_at);
        $this->assertSame(BackfillRepo::PDS, $this->repo($backfill, self::ALICE)->method);

        $this->assertSame('alice.test', Identity::query()->find(self::ALICE)?->handle, 'New repos get an identity, as Jetstream gives them.');
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'http://tap.test'));

        $progress = app(Backfills::class)->progress()[0];
        $this->assertSame([3, 2, 1, 2], [$progress['repos'], $progress['delivered'], $progress['unreachable'], $progress['records']]);
    }

    public function test_a_job_retried_reads_only_the_repos_it_had_not_finished(): void
    {
        $backfill = Backfill::query()->create(['collection' => self::POSTS, 'source' => 'test', 'started_at' => now()]);
        BackfillRepo::query()->insert([
            ['backfill_id' => $backfill->id, 'did' => self::ALICE, 'method' => BackfillRepo::PDS, 'delivered_at' => now()],
            ['backfill_id' => $backfill->id, 'did' => self::BOB, 'method' => BackfillRepo::PDS, 'delivered_at' => null],
        ]);

        (new ReconcileRepos($backfill->id, self::POSTS, [self::ALICE, self::BOB]))->handle(app(\SocialDept\AtpAppview\Index\Reconciler::class), app(Backfills::class));

        $this->assertArrayNotHasKey(self::ALICE, $this->listed, 'A repo already read is not read again.');
        $this->assertNotNull($this->repo($backfill, self::BOB)->delivered_at);
        $this->assertSame(Backfill::FINISHED, $backfill->refresh()->status);
    }

    public function test_a_backfill_where_no_repo_could_be_read_fails(): void
    {
        $this->atp->stub('com.atproto.sync.getLatestCommit', FakeResponse::error('InternalServerError', 'down', 500));

        $backfill = app(Backfills::class)->start(self::POSTS)->refresh();

        $this->assertSame(Backfill::FAILED, $backfill->status);
        $this->assertStringContainsString('No repo could be read', $backfill->error);
    }

    public function test_a_backfill_of_no_repos_finishes_at_once(): void
    {
        Http::fake([self::RELAY.'/*' => Http::response(['repos' => []])]);

        $this->assertSame(Backfill::FINISHED, app(Backfills::class)->start(self::POSTS)->refresh()->status);
    }

    public function test_the_command_says_the_repos_are_read_from_their_pdses(): void
    {
        $this->artisan('appview:backfill', ['collection' => [self::POSTS]])
            ->expectsOutputToContain('Read from their PDSes in the queue')
            ->assertSuccessful();
    }

    public function test_discover_points_at_backfill_with_jetstream(): void
    {
        $this->artisan('appview:discover')
            ->expectsOutputToContain('appview:backfill')
            ->assertFailed();
    }
}
