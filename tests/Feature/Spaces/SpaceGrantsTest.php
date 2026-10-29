<?php

namespace SocialDept\AtpAppview\Tests\Feature\Spaces;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpAppview\Auth\ViewerSource;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Index\RecordQuery;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Spaces\Contracts\SpaceAccess;
use SocialDept\AtpAppview\Spaces\Events\SpaceRecordIndexed;
use SocialDept\AtpAppview\Spaces\SpaceGrants;
use SocialDept\AtpAppview\Spaces\SpaceRecordIndexer;
use SocialDept\AtpAppview\Spaces\SpaceRecordQuery;
use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpAppview\Spaces\SyncSpace;
use SocialDept\AtpClient\AtpClient;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Auth\ServiceAuth;
use SocialDept\AtpClient\Contracts\CredentialProvider;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpClient\Data\Credentials;
use SocialDept\AtpClient\Data\ServiceAuthToken;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSpaces\AtpSpacesServiceProvider;
use SocialDept\AtpSpaces\Auth\CredentialKey;
use SocialDept\AtpSpaces\Auth\SpaceCredential;
use SocialDept\AtpSpaces\Auth\SpaceToken;
use SocialDept\AtpSpaces\Auth\SpaceTokenType;
use SocialDept\AtpSpaces\Contracts\CredentialStore;
use SocialDept\AtpSpaces\Exceptions\CredentialException;
use SocialDept\AtpSpaces\SpaceRef;
use SocialDept\AtpSpaces\Spaces;
use SocialDept\AtpSpaces\SpaceTypeRegistry;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Who may read a followed space: grants from credentials the authority
 * issues, entered with a server-side session or a delegation token the
 * browser hands over, expiring with the credential. SpaceRecordQuery reads
 * a space only for a viewer SpaceAccess allows, with blocks and moderation
 * applied, and RecordQuery never sees a space record.
 */
class SpaceGrantsTest extends TestCase
{
    protected const AUTHORITY = 'did:plc:authority';
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const CAROL = 'did:plc:carol';
    protected const POST = 'com.example.post';
    protected const ENTER = 'com.example.space.enter';
    protected const SERVICE = 'did:web:app.example';

    protected Spaces&MockInterface $spaces;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpSpacesServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.middleware', ['web']);
        $app['config']->set('atp-client.service_auth.audience', self::SERVICE.'#atproto_appview');
        $app['config']->set('atp-appview.ingest.collections', [self::POST]);
        $app['config']->set('atp-appview.fields', [self::POST => ['tags' => 'string[]']]);
        $app['config']->set('atp-appview.features', [Features::moderation(), Features::spaces()]);
        $app['config']->set('atp-appview.spaces.xrpc.enter', self::ENTER);
        $app['config']->set('auth.providers.users.model', GrantsUser::class);
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

        Queue::fake();
        Event::fake([SpaceRecordIndexed::class]);

        $this->app->instance(CredentialStore::class, new class () implements CredentialStore {
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
        });

        $this->spaces = Mockery::mock(Spaces::class);
        $this->spaces->shouldReceive('types')->andReturn(new SpaceTypeRegistry())->byDefault();
        $this->app->instance(Spaces::class, $this->spaces);

        // Followed with Bob's credential, so Bob holds a grant from the start.
        $this->sync()->follow($this->credential(self::BOB));
    }

    protected function space(): SpaceRef
    {
        return SpaceRef::make(self::AUTHORITY, 'com.example.forum', 'main');
    }

    protected function credential(string $did, int $expiresIn = 600): SpaceCredential
    {
        return new SpaceCredential(
            $this->space(),
            $did,
            new SpaceToken(SpaceTokenType::Credential, [], ['exp' => time() + $expiresIn], 'header.payload.signature'),
            CredentialKey::generate(),
        );
    }

    protected function delegationToken(string $did): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['typ' => 'atproto-space-delegation+jwt', 'alg' => 'ES256', 'kid' => '#atproto'])
            .'.'.$encode(['iss' => $did, 'sub' => (string) $this->space(), 'aud' => self::AUTHORITY.'#atproto_pds', 'exp' => time() + 60, 'jti' => 'j1'])
            .'.signature';
    }

    protected function sync(): SpaceSync
    {
        return app(SpaceSync::class);
    }

    protected function grants(): SpaceGrants
    {
        return app(SpaceGrants::class);
    }

    protected function serverSession(string $did): void
    {
        $provider = Mockery::mock(CredentialProvider::class);
        $provider->shouldReceive('getCredentials')->andReturnUsing(fn (string $asked) => $asked === $did ? new Credentials(did: $asked, accessToken: 'a', refreshToken: 'r', expiresAt: now()->addHour()) : null);
        $this->app->instance(CredentialProvider::class, $provider);
    }

    protected function grant(string $did): ?object
    {
        return DB::table('atp_space_grants')->where('space', (string) $this->space())->where('did', $did)->first();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function record(string $did, string $rkey, array $record = [], string $sortAt = '2026-10-29 12:00:00.000000'): string
    {
        $uri = (string) $this->space()->record($did, self::POST, $rkey);

        DB::table('atp_space_records')->insert([
            'uri' => $uri,
            'space' => (string) $this->space(),
            'did' => $did,
            'collection' => self::POST,
            'rkey' => $rkey,
            'cid' => "bafy-{$rkey}",
            'rev' => '3l2aaaaaaaaaa',
            'record' => json_encode($record + ['text' => $rkey]),
            'indexed_at' => '2026-10-29 12:00:00',
            'sort_at' => $sortAt,
        ]);

        return $uri;
    }

    /**
     * @return array<int, string>
     */
    protected function visible(?string $viewer, ?string $did = null): array
    {
        $query = SpaceRecordQuery::in($this->space())->visibleTo($viewer);

        if ($did !== null) {
            $query->by($did);
        }

        return $query->get()['records']->pluck('rkey')->all();
    }

    public function test_following_grants_the_account_that_followed(): void
    {
        $this->assertTrue($this->grants()->allows($this->space(), self::BOB));
        $this->assertFalse($this->grants()->allows($this->space(), self::ALICE));
        $this->assertFalse($this->grants()->allows($this->space(), null));
    }

    public function test_entering_with_a_server_side_session_grants_until_the_credential_expires(): void
    {
        $this->serverSession(self::ALICE);
        $credential = $this->credential(self::ALICE);
        $this->spaces->shouldReceive('credential')
            ->withArgs(fn (SpaceRef $space, AtpClient $client, ?string $attestation) => (string) $space === (string) $this->space() && $attestation === null)
            ->once()->andReturn($credential);

        $expires = $this->grants()->enter($this->space(), self::ALICE);

        $this->assertSame($credential->expiresAt(), $expires->getTimestamp());
        $this->assertSame($expires->format('Y-m-d H:i:s'), Carbon::parse($this->grant(self::ALICE)->expires_at)->format('Y-m-d H:i:s'));
        $this->assertTrue($this->grants()->allows($this->space(), self::ALICE));
        $this->assertSame(self::ALICE, $this->sync()->state($this->space())->credential_did);
    }

    public function test_entering_in_backend_mode_needs_a_server_side_session(): void
    {
        $this->serverSession(self::BOB);
        $this->spaces->shouldNotReceive('credential');

        $this->expectException(CredentialException::class);
        $this->expectExceptionMessage('delegation token');

        $this->grants()->enter($this->space(), new Viewer(self::ALICE, ViewerSource::ServiceAuth));
    }

    public function test_entering_with_a_delegation_token_grants_the_viewer_it_names(): void
    {
        $token = $this->delegationToken(self::ALICE);
        $this->spaces->shouldReceive('credentialFromDelegation')->with(Mockery::on(fn ($space) => (string) $space === (string) $this->space()), $token, null)
            ->once()->andReturn($this->credential(self::ALICE));

        $this->grants()->enterFromDelegation($this->space(), self::ALICE, $token);

        $this->assertTrue($this->grants()->allows($this->space(), self::ALICE));
        $this->assertSame(self::ALICE, $this->sync()->state($this->space())->credential_did);
    }

    public function test_the_procedure_enters_by_session_or_service_auth(): void
    {
        $alice = GrantsUser::create(['did' => self::ALICE]);
        $credential = $this->credential(self::ALICE);
        $this->spaces->shouldReceive('credentialFromDelegation')->twice()->andReturn($credential);
        $expected = ['space' => (string) $this->space(), 'expiresAt' => CarbonImmutable::createFromTimestampUTC($credential->expiresAt())->toIso8601ZuluString()];
        $input = ['space' => (string) $this->space(), 'delegationToken' => $this->delegationToken(self::ALICE)];

        $this->postJson('/xrpc/'.self::ENTER, $input)->assertStatus(401);

        $this->actingAs($alice, 'web')->postJson('/xrpc/'.self::ENTER, $input)->assertOk()->assertExactJson($expected);

        DB::table('atp_space_grants')->where('did', self::ALICE)->delete();
        $this->app['auth']->forgetGuards();
        $this->mock(ServiceAuth::class, function ($mock) {
            $mock->shouldReceive('fromHeader')->andReturn('jwt');
            $mock->shouldReceive('verify')->with('jwt', self::SERVICE.'#atproto_appview', self::ENTER)
                ->andReturn(new ServiceAuthToken(self::ALICE, self::SERVICE, self::ENTER, time() + 60));
        });

        $this->postJson('/xrpc/'.self::ENTER, $input, ['Authorization' => 'Bearer jwt'])->assertOk()->assertExactJson($expected);
        $this->assertTrue($this->grants()->allows($this->space(), self::ALICE));
    }

    public function test_the_procedure_refuses_a_token_naming_another_viewer(): void
    {
        $alice = GrantsUser::create(['did' => self::ALICE]);
        $this->spaces->shouldNotReceive('credentialFromDelegation');

        $this->actingAs($alice, 'web')->postJson('/xrpc/'.self::ENTER, ['space' => (string) $this->space(), 'delegationToken' => $this->delegationToken(self::CAROL)])
            ->assertStatus(400)->assertJsonPath('error', 'InvalidDelegationToken');
        $this->actingAs($alice, 'web')->postJson('/xrpc/'.self::ENTER, ['space' => (string) $this->space(), 'delegationToken' => 'not-a-token'])
            ->assertStatus(400);
        $this->actingAs($alice, 'web')->postJson('/xrpc/'.self::ENTER, ['space' => 'nope', 'delegationToken' => $this->delegationToken(self::ALICE)])
            ->assertStatus(400)->assertJsonPath('error', 'InvalidRequest');

        $this->assertNull($this->grant(self::ALICE));
        $this->assertNull($this->grant(self::CAROL));
    }

    public function test_a_refusal_from_the_authority_drops_the_viewers_grant(): void
    {
        $this->spaces->shouldReceive('credentialFromDelegation')->andThrow(new CredentialException('No.', 'UserNotAuthorized'));
        $this->assertNotNull($this->grant(self::BOB));

        try {
            $this->grants()->enterFromDelegation($this->space(), self::BOB, $this->delegationToken(self::BOB));
            $this->fail('The refusal should be rethrown.');
        } catch (CredentialException $e) {
            $this->assertTrue($e->isUserRefusal());
        }

        $this->assertNull($this->grant(self::BOB));
        $this->assertFalse($this->grants()->allows($this->space(), self::BOB));
    }

    public function test_a_failure_that_is_not_a_refusal_keeps_the_grant(): void
    {
        $this->spaces->shouldReceive('credentialFromDelegation')->andThrow(new CredentialException('Timed out.'));

        $this->expectException(CredentialException::class);

        try {
            $this->grants()->enterFromDelegation($this->space(), self::BOB, $this->delegationToken(self::BOB));
        } finally {
            $this->assertNotNull($this->grant(self::BOB));
        }
    }

    public function test_a_space_the_authority_says_is_deleted_is_dropped(): void
    {
        $this->record(self::BOB, 'one');
        $this->spaces->shouldReceive('credentialFromDelegation')->andThrow(new CredentialException('Gone.', 'SpaceDeleted'));

        try {
            $this->grants()->enterFromDelegation($this->space(), self::ALICE, $this->delegationToken(self::ALICE));
            $this->fail('The refusal should be rethrown.');
        } catch (CredentialException $e) {
            $this->assertTrue($e->isSpaceDeleted());
        }

        $this->assertNotNull($this->sync()->state($this->space())->deleted_at);
        $this->assertSame(0, DB::table('atp_space_grants')->count());
        $this->assertNotNull(DB::table('atp_space_records')->value('deleted_at'));

        $this->expectException(InvalidArgumentException::class);
        $this->grants()->enterFromDelegation($this->space(), self::ALICE, $this->delegationToken(self::ALICE));
    }

    public function test_only_a_followed_space_can_be_entered(): void
    {
        $this->sync()->unfollow($this->space());
        $this->spaces->shouldNotReceive('credentialFromDelegation');
        $alice = GrantsUser::create(['did' => self::ALICE]);

        $this->actingAs($alice, 'web')->postJson('/xrpc/'.self::ENTER, ['space' => (string) $this->space(), 'delegationToken' => $this->delegationToken(self::ALICE)])
            ->assertStatus(404)->assertJsonPath('error', 'SpaceNotFound');
        $this->assertSame(0, DB::table('atp_space_grants')->count());
    }

    public function test_entering_a_stale_space_resumes_its_sync(): void
    {
        Queue::fake();
        DB::table('atp_spaces')->update(['stale_at' => '2026-10-29 00:00:00', 'last_error' => 'No usable credential']);
        $this->spaces->shouldReceive('credentialFromDelegation')->andReturn($this->credential(self::ALICE));

        $this->grants()->enterFromDelegation($this->space(), self::ALICE, $this->delegationToken(self::ALICE));

        $row = $this->sync()->state($this->space());
        $this->assertNull($row->stale_at);
        $this->assertSame(self::ALICE, $row->credential_did);
        Queue::assertPushed(SyncSpace::class, fn (SyncSpace $job) => $job->space === (string) $this->space());
    }

    public function test_entering_a_space_that_is_not_stale_queues_nothing(): void
    {
        Queue::fake();
        $this->spaces->shouldReceive('credentialFromDelegation')->andReturn($this->credential(self::ALICE));

        $this->grants()->enterFromDelegation($this->space(), self::ALICE, $this->delegationToken(self::ALICE));

        Queue::assertNotPushed(SyncSpace::class);
    }

    public function test_the_query_finds_nothing_without_a_grant(): void
    {
        $this->record(self::BOB, 'one');

        $this->assertSame(['one'], $this->visible(self::BOB));
        $this->assertSame([], $this->visible(self::ALICE));
        $this->assertSame([], $this->visible(null));
        $this->assertSame([], SpaceRecordQuery::in($this->space())->get()['records']->all());
    }

    public function test_an_expired_grant_hides_records_and_is_pruned(): void
    {
        $this->record(self::BOB, 'one');
        DB::table('atp_space_grants')->update(['expires_at' => Carbon::now('UTC')->subSecond()->format('Y-m-d H:i:s')]);

        $this->assertFalse($this->grants()->allows($this->space(), self::BOB));
        $this->assertSame([], $this->visible(self::BOB));

        $this->artisan('appview:spaces:sync', ['--queue' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('atp_space_grants')->count());
    }

    public function test_the_query_pages_newest_first_by_collection_and_skips_tombstones(): void
    {
        $this->record(self::BOB, 'a', sortAt: '2026-10-29 12:00:01.000000');
        $this->record(self::BOB, 'b', sortAt: '2026-10-29 12:00:02.000000');
        $this->record(self::ALICE, 'c', sortAt: '2026-10-29 12:00:02.000000');
        $this->record(self::BOB, 'd', sortAt: '2026-10-29 12:00:03.000000');
        DB::table('atp_space_records')->where('rkey', 'd')->update(['deleted_at' => '2026-10-29 13:00:00', 'record' => null]);
        DB::table('atp_space_records')->insert([
            'uri' => (string) $this->space()->record(self::BOB, 'com.example.like', 'e'),
            'space' => (string) $this->space(), 'did' => self::BOB, 'collection' => 'com.example.like', 'rkey' => 'e',
            'rev' => '3l2aaaaaaaaaa', 'record' => '{}', 'indexed_at' => '2026-10-29 12:00:00', 'sort_at' => '2026-10-29 12:00:09.000000',
        ]);

        $first = SpaceRecordQuery::in($this->space())->visibleTo(self::BOB)->collection(self::POST)->limit(2)->get();
        $second = SpaceRecordQuery::in($this->space())->visibleTo(self::BOB)->collection(self::POST)->limit(2)->after($first['cursor'])->get();

        $this->assertSame(['b', 'c'], $first['records']->pluck('rkey')->all());
        $this->assertSame(['a'], $second['records']->pluck('rkey')->all());
        $this->assertNull($second['cursor']);
        $this->assertSame(['text' => 'a'], $second['records']->first()->record);
        $this->assertSame(['e', 'b', 'a'], $this->visible(self::BOB, self::BOB));
    }

    public function test_blocked_taken_down_and_labelled_records_stay_hidden(): void
    {
        $this->record(self::BOB, 'own');
        $this->record(self::ALICE, 'blocked');
        $this->record(self::CAROL, 'taken-down');
        $this->record('did:plc:dave', 'banned');
        $labelled = $this->record('did:plc:erin', 'labelled');
        $this->record('did:plc:erin', 'review');
        $this->record('did:plc:frank', 'fine');

        app(AppView::class)->readFilter(fn (string $viewer) => $viewer === self::BOB ? [self::ALICE] : []);
        DB::table('atp_identities')->insert(['did' => self::CAROL, 'active' => false, 'status' => 'takendown', 'updated_at' => '2026-10-29 00:00:00']);
        DB::table('atp_account_actions')->insert(['did' => 'did:plc:dave', 'action' => 'ban', 'source' => 'manual', 'created_at' => '2026-10-29 00:00:00', 'updated_at' => '2026-10-29 00:00:00']);
        DB::table('atp_labels')->insert(['src' => 'did:plc:labeler', 'uri' => $labelled, 'subject' => self::AUTHORITY, 'val' => '!hide', 'cts' => '2026-10-29T00:00:00Z']);
        DB::table('atp_labels')->insert(['src' => 'did:plc:labeler', 'uri' => (string) $this->space()->record('did:plc:erin', self::POST, 'review'), 'subject' => self::AUTHORITY, 'val' => 'needs-review', 'cts' => '2026-10-29T00:00:00Z']);

        $this->assertEqualsCanonicalizing(['own', 'fine'], $this->visible(self::BOB));

        // Asked for one repo, what needs review is shown.
        $this->assertSame(['review'], $this->visible(self::BOB, 'did:plc:erin'));
    }

    public function test_a_hiding_label_on_an_account_hides_its_space_records(): void
    {
        $this->record(self::ALICE, 'one');
        DB::table('atp_labels')->insert(['src' => 'did:plc:labeler', 'uri' => self::ALICE, 'subject' => self::ALICE, 'val' => '!takedown', 'cts' => '2026-10-29T00:00:00Z']);

        $this->assertSame([], $this->visible(self::BOB));
    }

    public function test_a_custom_space_access_answers_instead_of_the_grants(): void
    {
        $this->record(self::BOB, 'one');
        $this->app->instance(SpaceAccess::class, new class () implements SpaceAccess {
            public function allows(SpaceRef $space, ?string $viewer): bool
            {
                return $viewer === 'did:plc:carol';
            }
        });

        $this->assertSame(['one'], $this->visible(self::CAROL));
        $this->assertSame([], $this->visible(self::BOB));
    }

    public function test_record_query_never_returns_space_records(): void
    {
        $space = new RecordEvent(1, self::BOB, self::POST, 'private', RecordAction::Create, 'bafy-private', '3l2aaaaaaaaaa', ['tags' => ['php']], true, space: (string) $this->space());
        $public = new RecordEvent(2, self::BOB, self::POST, 'public', RecordAction::Create, 'bafy-public', '3l2aaaaaaaaab', ['tags' => ['php']], true);

        // Through every configured pipe, and through the space indexer.
        Pipeline::fromConfig(app())->run(new Batch([$space, $public]));
        (new Pipeline(app(), [app(SpaceRecordIndexer::class)]))->run(new Batch([$space]));

        $this->assertSame(1, DB::table('atp_space_records')->count());
        $this->assertSame(['at://'.self::BOB.'/'.self::POST.'/public'], RecordQuery::in(self::POST)->where('tags', 'php')->get()['records']->pluck('uri')->all());
        $this->assertSame(['private'], $this->visible(self::BOB));
    }
}

class GrantsUser extends Authenticatable implements HasAtpSession
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getAtpDid(): ?string
    {
        return $this->did;
    }
}
