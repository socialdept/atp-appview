<?php

namespace SocialDept\AtpAppview\Tests\Feature\Xrpc;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Contracts\IngestHook;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Xrpc\XrpcRegistry;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Contracts\CredentialProvider;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpClient\Data\Credentials;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpClient\Testing\FakeAtpManager;
use SocialDept\AtpClient\Testing\FakeResponse;
use SocialDept\AtpClient\Testing\RecordedRequest;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use SocialDept\AtpSupport\Facades\Resolver;

/**
 * Writing records: default procedures write through a server-held session
 * (backend mode), and the sync procedure indexes what a browser wrote to its
 * own PDS (frontend mode).
 */
class ProceduresTest extends TestCase
{
    protected const DID = 'did:plc:alice';
    protected const COLLECTION = 'com.example.thing';
    protected const REV = '3m2zzzzzzzzzz';

    protected FakeAtpManager $atp;

    protected ProceduresUser $user;

    protected bool $holdsSession = true;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.middleware', ['web']);
        $app['config']->set('atp-appview.ingest.collections', [self::COLLECTION]);
        $app['config']->set('atp-appview.xrpc.procedures', [
            'com.example.makeThing' => ['collection' => self::COLLECTION, 'action' => 'create'],
            'com.example.replaceThing' => ['collection' => self::COLLECTION, 'action' => 'put'],
            'com.example.removeThing' => ['collection' => self::COLLECTION, 'action' => 'delete'],
        ]);
        $app['config']->set('atp-appview.xrpc.sync_record', 'com.example.syncRecord');
        $app['config']->set('auth.providers.users.model', ProceduresUser::class);
        $app['config']->set('auth.guards.atp', ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('did');
            $table->rememberToken();
        });
        $this->user = ProceduresUser::create(['did' => self::DID]);

        // Backend mode: the server holds a session for alice, unless a test
        // turns it off to act as a frontend-mode account.
        $credentials = $this->createStub(CredentialProvider::class);
        $credentials->method('getCredentials')->willReturnCallback(fn (string $did) => $this->holdsSession && $did === self::DID
            ? new Credentials(did: $did, accessToken: 'a', refreshToken: 'r', expiresAt: now()->addHour())
            : null);
        $this->app->instance(CredentialProvider::class, $credentials);
    }

    public function test_create_writes_through_the_viewers_session_and_indexes_straight_away(): void
    {
        $this->atp = Atp::fake();
        $this->atp->stub('com.atproto.repo.createRecord', ['uri' => 'at://did:plc:alice/com.example.thing/3m2aaaaaaaaab', 'cid' => 'bafynew', 'commit' => ['cid' => 'bafycommit', 'rev' => self::REV]]);

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.makeThing', ['record' => ['text' => 'hello', 'createdAt' => '2026-10-01T00:00:00Z']])
            ->assertOk()
            ->assertExactJson(['uri' => 'at://did:plc:alice/com.example.thing/3m2aaaaaaaaab', 'cid' => 'bafynew']);

        $record = Record::find('at://did:plc:alice/com.example.thing/3m2aaaaaaaaab');
        $this->assertSame(['text' => 'hello', 'createdAt' => '2026-10-01T00:00:00Z', '$type' => self::COLLECTION], $record->record);
        $this->assertSame(self::REV, $record->rev);
        $this->assertSame('bafynew', $record->cid);

        $this->atp->assertCalledAuthenticated('com.atproto.repo.createRecord', self::DID);
        $this->atp->assertCalled('com.atproto.repo.createRecord', fn (RecordedRequest $request) => $request->body['repo'] === self::DID
            && $request->body['collection'] === self::COLLECTION
            && $request->body['record'] === ['text' => 'hello', 'createdAt' => '2026-10-01T00:00:00Z', '$type' => self::COLLECTION]
            && ($request->body['validate'] ?? null) === false);
    }

    public function test_put_and_delete_write_and_index_too(): void
    {
        $this->atp = Atp::fake();
        $this->atp->stub('com.atproto.repo.putRecord', ['uri' => 'at://did:plc:alice/com.example.thing/self', 'cid' => 'bafyput', 'commit' => ['cid' => 'c', 'rev' => self::REV]]);
        $this->atp->stub('com.atproto.repo.deleteRecord', ['commit' => ['cid' => 'c', 'rev' => self::REV]]);

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.replaceThing', ['rkey' => 'self', 'record' => ['text' => 'v2'], 'swapRecord' => 'bafyold'])
            ->assertOk()
            ->assertJson(['cid' => 'bafyput']);
        $this->assertSame(['text' => 'v2', '$type' => self::COLLECTION], Record::find('at://did:plc:alice/com.example.thing/self')->record);

        $this->actingAs($this->user, 'web')->postJson('/xrpc/com.example.removeThing', ['rkey' => 'self'])->assertOk();
        $this->assertNull(Record::find('at://did:plc:alice/com.example.thing/self'));
        $this->assertNotNull(Record::withTrashed()->find('at://did:plc:alice/com.example.thing/self')->deleted_at);

        $this->atp->assertCalled('com.atproto.repo.putRecord', fn (RecordedRequest $request) => $request->body['rkey'] === 'self'
            && $request->body['record'] === ['text' => 'v2', '$type' => self::COLLECTION]
            && $request->body['swapRecord'] === 'bafyold');
        $this->atp->assertCalled('com.atproto.repo.deleteRecord', fn (RecordedRequest $request) => $request->body['rkey'] === 'self'
            && $request->body['collection'] === self::COLLECTION);
    }

    public function test_a_record_its_lexicon_refuses_never_reaches_the_pds(): void
    {
        $this->atp = Atp::fake();

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.makeThing', ['record' => ['createdAt' => '2026-10-01T00:00:00Z']])
            ->assertStatus(400)
            ->assertJson(['error' => 'InvalidRequest']);

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.makeThing', ['record' => ['$type' => 'com.example.other', 'text' => 'x']])
            ->assertStatus(400);

        $this->atp->assertNothingCalled();
    }

    public function test_a_frontend_mode_account_is_told_to_write_to_its_pds(): void
    {
        $this->holdsSession = false;

        $this->postJson('/xrpc/com.example.makeThing', ['record' => ['text' => 'hello']])->assertStatus(401);

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.makeThing', ['record' => ['text' => 'hello']])
            ->assertStatus(403)
            ->assertJson(['error' => 'BackendSessionRequired']);
    }

    public function test_a_blocked_repo_cannot_write_through_the_server(): void
    {
        app(Blocklist::class)->block(self::DID, self::COLLECTION);
        $this->atp = Atp::fake();

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.makeThing', ['record' => ['text' => 'hello']])
            ->assertStatus(403)
            ->assertJson(['error' => 'RepoBlocked']);

        $this->atp->assertNothingCalled();
    }

    public function test_the_pdss_own_error_comes_back_as_it_was(): void
    {
        $this->atp = Atp::fake();
        $this->atp->stub('com.atproto.repo.putRecord', FakeResponse::error('InvalidSwap', 'Record was at bafyother', 400));

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.replaceThing', ['rkey' => 'self', 'record' => ['text' => 'v2'], 'swapRecord' => 'bafyold'])
            ->assertStatus(400)
            ->assertExactJson(['error' => 'InvalidSwap', 'message' => 'Record was at bafyother']);
    }

    public function test_sync_indexes_a_record_the_browser_wrote_to_its_pds(): void
    {
        $this->holdsSession = false;
        Resolver::shouldReceive('resolvePds')->with(self::DID)->andReturn('https://pds.example');
        $this->atp = Atp::fake();
        $this->atp->stub('com.atproto.sync.getLatestCommit', ['cid' => 'c', 'rev' => self::REV]);
        $this->atp->stub('com.atproto.repo.getRecord', ['uri' => 'at://did:plc:alice/com.example.thing/3m2aaaaaaaaab', 'cid' => 'bafyfrompds', 'value' => ['$type' => self::COLLECTION, 'text' => 'from the browser']]);

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.syncRecord', ['uri' => 'at://did:plc:alice/com.example.thing/3m2aaaaaaaaab'])
            ->assertOk()
            ->assertExactJson(['uri' => 'at://did:plc:alice/com.example.thing/3m2aaaaaaaaab', 'indexed' => true]);

        $record = Record::find('at://did:plc:alice/com.example.thing/3m2aaaaaaaaab');
        $this->assertSame('from the browser', $record->record['text']);
        $this->assertSame('bafyfrompds', $record->cid);
        $this->assertSame(self::REV, $record->rev);
    }

    public function test_sync_tombstones_a_record_that_is_gone(): void
    {
        $this->holdsSession = false;
        Resolver::shouldReceive('resolvePds')->andReturn('https://pds.example');
        $this->atp = Atp::fake();
        $this->atp->stub('com.atproto.sync.getLatestCommit', ['cid' => 'c', 'rev' => self::REV]);
        $this->atp->stub('com.atproto.repo.getRecord', FakeResponse::error('RecordNotFound', 'Could not locate record', 400));

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.syncRecord', ['uri' => 'at://did:plc:alice/com.example.thing/gone'])
            ->assertOk();

        $this->assertNotNull(Record::withTrashed()->find('at://did:plc:alice/com.example.thing/gone')->deleted_at);
    }

    public function test_sync_runs_the_ingest_hooks_like_any_delivery(): void
    {
        config()->set('atp-appview.ingest.hooks', [RejectsEverything::class]);
        $this->holdsSession = false;
        Resolver::shouldReceive('resolvePds')->andReturn('https://pds.example');
        $this->atp = Atp::fake();
        $this->atp->stub('com.atproto.sync.getLatestCommit', ['cid' => 'c', 'rev' => self::REV]);
        $this->atp->stub('com.atproto.repo.getRecord', ['uri' => 'at://did:plc:alice/com.example.thing/3m2aaaaaaaaab', 'cid' => 'bafyfrompds', 'value' => ['$type' => self::COLLECTION, 'text' => 'spam']]);

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.syncRecord', ['uri' => 'at://did:plc:alice/com.example.thing/3m2aaaaaaaaab'])
            ->assertOk()
            ->assertExactJson(['uri' => 'at://did:plc:alice/com.example.thing/3m2aaaaaaaaab', 'indexed' => false]);

        $this->assertNull(Record::find('at://did:plc:alice/com.example.thing/3m2aaaaaaaaab'));
    }

    public function test_sync_takes_only_the_callers_own_records_in_indexed_collections(): void
    {
        $this->atp = Atp::fake();

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.syncRecord', ['uri' => 'at://did:plc:bob/com.example.thing/3m2aaaaaaaaab'])
            ->assertStatus(403)
            ->assertJson(['error' => 'NotYourRecord']);

        $this->actingAs($this->user, 'web')
            ->postJson('/xrpc/com.example.syncRecord', ['uri' => 'at://did:plc:alice/app.example.unindexed/3m2aaaaaaaaab'])
            ->assertStatus(400);

        $this->actingAs($this->user, 'web')->postJson('/xrpc/com.example.syncRecord', ['uri' => 'not a uri'])->assertStatus(400);

        $this->atp->assertNotCalled('com.atproto.repo.getRecord');
    }

    public function test_a_procedure_needs_a_collection_and_a_known_action(): void
    {
        config()->set('atp-appview.xrpc.procedures', ['com.example.oops' => ['collection' => self::COLLECTION, 'action' => 'upsert']]);

        $this->expectException(InvalidArgumentException::class);
        (new XrpcRegistry())->routes();
    }
}

class ProceduresUser extends Authenticatable implements HasAtpSession
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getAtpDid(): ?string
    {
        return $this->did;
    }
}

class RejectsEverything implements IngestHook
{
    public function reject(array $records): array
    {
        return array_fill_keys(array_map(fn ($event) => $event->uri(), $records), 'no');
    }
}
