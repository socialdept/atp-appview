<?php

namespace SocialDept\AtpAppview\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Math\BigInteger;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpAppview\Auth\ViewerSource;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Auth\ServiceAuth;
use SocialDept\AtpClient\Contracts\CredentialProvider;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpClient\Data\Credentials;
use SocialDept\AtpSupport\Crypto\Base58;
use SocialDept\AtpSupport\Crypto\DidKey;
use SocialDept\AtpSupport\Crypto\SignatureVerifier;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Resolver;

class AtpGuardTest extends TestCase
{
    protected const CALLER = 'did:plc:alice';
    protected const SERVICE = 'did:web:feed.example#bsky_fg';
    protected const AUDIENCE = 'did:web:app.example#atproto_appview';
    protected const METHOD = 'com.example.getTimeline';
    protected const ORDER = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';

    protected PrivateKey $key;

    protected function getPackageProviders($app): array
    {
        return [AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', GuardTestUser::class);
        $app['config']->set('auth.guards.atp', ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web']);
        $app['config']->set('atp-client.service_auth.audience', self::AUDIENCE);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('did')->unique();
            $table->rememberToken();
            $table->timestamps();
        });

        $this->key = EC::createKey('secp256k1');

        // Publish this test's key as the #atproto key of every DID it resolves.
        $resolver = $this->createStub(Resolver::class);
        $resolver->method('resolveDid')->willReturnCallback(fn (string $did) => DidDocument::fromArray([
            'id' => $did,
            'verificationMethod' => [[
                'id' => $did.'#atproto',
                'publicKeyMultibase' => substr($this->didKey(), strlen('did:key:')),
            ]],
        ]));
        $this->app->instance(ServiceAuth::class, new ServiceAuth($resolver, new SignatureVerifier()));

        $echo = fn (Request $request) => response()->json($this->describe($request->viewer()));

        Route::middleware('atp.viewer:required,'.self::METHOD)->get('/xrpc/'.self::METHOD, $echo);
        Route::middleware('atp.viewer:optional,com.example.getThread')->get('/xrpc/com.example.getThread', $echo);
        Route::middleware(['web', 'atp.viewer:required'])->get('/dashboard', $echo);
    }

    public function test_a_service_auth_token_resolves_a_viewer_and_provisions_the_user(): void
    {
        $this->bearing($this->mint())->getJson('/xrpc/'.self::METHOD)
            ->assertOk()
            ->assertJson(['did' => self::CALLER, 'source' => 'service_auth', 'method' => self::METHOD, 'hasUser' => true]);

        $this->assertDatabaseHas('users', ['did' => self::CALLER]);
    }

    public function test_an_existing_user_is_reused(): void
    {
        $user = GuardTestUser::create(['did' => self::CALLER]);

        $this->bearing($this->mint())->getJson('/xrpc/'.self::METHOD)->assertJson(['userId' => $user->id]);

        $this->assertSame(1, GuardTestUser::count());
    }

    public function test_no_user_is_created_when_provisioning_is_off(): void
    {
        config()->set('atp-appview.auth.provision', 'none');

        $this->bearing($this->mint())->getJson('/xrpc/'.self::METHOD)
            ->assertOk()
            ->assertJson(['did' => self::CALLER, 'hasUser' => false]);

        $this->assertSame(0, GuardTestUser::count());
    }

    public function test_a_session_user_is_the_viewer_without_a_bearer(): void
    {
        $user = GuardTestUser::create(['did' => self::CALLER]);

        $this->actingAs($user, 'web')->getJson('/dashboard')
            ->assertOk()
            ->assertJson(['did' => self::CALLER, 'source' => 'session', 'method' => null]);
    }

    public function test_a_banned_account_is_nobody(): void
    {
        $this->artisan('migrate')->run();
        $user = GuardTestUser::create(['did' => self::CALLER]);
        app(\SocialDept\AtpAppview\Moderation\AccountActions::class)->ban(self::CALLER, 'spam');

        $this->actingAs($user, 'web')->getJson('/dashboard')->assertUnauthorized();
        $this->bearing($this->mint())->getJson('/xrpc/'.self::METHOD)->assertUnauthorized();
    }

    public function test_a_bad_bearer_never_falls_back_to_the_session(): void
    {
        $user = GuardTestUser::create(['did' => self::CALLER]);

        $this->actingAs($user, 'web')
            ->bearing($this->mint(method: 'com.example.somethingElse'))
            ->getJson('/xrpc/'.self::METHOD)
            ->assertStatus(401)
            ->assertJson(['error' => 'BadJwtLexiconMethod']);
    }

    public function test_a_token_without_a_method_is_refused(): void
    {
        $this->bearing($this->mint(method: null))->getJson('/xrpc/'.self::METHOD)
            ->assertStatus(401)
            ->assertJson(['error' => 'BadJwtLexiconMethod']);
    }

    public function test_a_route_without_a_method_refuses_service_auth(): void
    {
        $this->bearing($this->mint())->getJson('/dashboard')
            ->assertStatus(401)
            ->assertJson(['error' => 'BadJwtLexiconMethod']);
    }

    public function test_an_oauth_access_token_is_refused(): void
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $accessToken = $encode(['typ' => 'at+jwt', 'alg' => 'ES256']).'.'.$encode(['sub' => self::CALLER]).'.sig';

        $this->bearing($accessToken)->getJson('/xrpc/'.self::METHOD)
            ->assertStatus(401)
            ->assertJson(['error' => 'InvalidToken']);
    }

    public function test_a_bare_did_audience_matches_the_combined_configured_audience(): void
    {
        $this->bearing($this->mint(audience: 'did:web:app.example'))->getJson('/xrpc/'.self::METHOD)->assertOk();
    }

    public function test_every_service_auth_request_is_refused_without_a_configured_audience(): void
    {
        config()->set('atp-client.service_auth.audience', null);

        $this->bearing($this->mint())->getJson('/xrpc/'.self::METHOD)
            ->assertStatus(500)
            ->assertJson(['error' => 'InternalServerError']);
    }

    public function test_an_untrusted_service_is_refused(): void
    {
        $this->bearing($this->mint(issuer: self::SERVICE))->getJson('/xrpc/'.self::METHOD)
            ->assertStatus(401)
            ->assertJson(['error' => 'BadJwtIss']);
    }

    public function test_a_trusted_service_is_a_viewer_without_a_user(): void
    {
        config()->set('atp-appview.auth.trusted_services', [self::SERVICE]);

        $this->bearing($this->mint(issuer: self::SERVICE))->getJson('/xrpc/'.self::METHOD)
            ->assertOk()
            ->assertJson(['did' => 'did:web:feed.example', 'isService' => true, 'hasUser' => false]);

        $this->assertSame(0, GuardTestUser::count());
    }

    public function test_a_required_route_refuses_an_anonymous_request(): void
    {
        $this->getJson('/xrpc/'.self::METHOD)
            ->assertStatus(401)
            ->assertJson(['error' => 'AuthenticationRequired']);
    }

    public function test_an_optional_route_serves_an_anonymous_request(): void
    {
        $this->getJson('/xrpc/com.example.getThread')->assertOk()->assertExactJson(['viewer' => null]);
    }

    public function test_the_viewer_can_act_on_the_repo_only_when_the_server_holds_a_session(): void
    {
        $credentials = $this->createStub(CredentialProvider::class);
        $credentials->method('getCredentials')->willReturnCallback(fn (string $did) => $did === self::CALLER
            ? new Credentials(did: $did, accessToken: 'a', refreshToken: 'r', expiresAt: now()->addHour())
            : null);
        $this->app->instance(CredentialProvider::class, $credentials);

        $this->assertTrue((new Viewer(self::CALLER, ViewerSource::Session))->canActOnRepo());
        $this->assertFalse((new Viewer('did:plc:bob', ViewerSource::ServiceAuth))->canActOnRepo());
        $this->assertNull((new Viewer('did:plc:bob', ViewerSource::ServiceAuth))->client());
        $this->assertFalse((new Viewer('did:web:feed.example', ViewerSource::ServiceAuth, serviceId: 'bsky_fg'))->canActOnRepo());
    }

    public function test_the_guard_works_with_laravels_auth_middleware(): void
    {
        $user = GuardTestUser::create(['did' => self::CALLER]);
        Route::middleware(['web', 'auth:atp'])->get('/account', fn () => response()->json(['id' => auth('atp')->id()]));

        $this->actingAs($user, 'web')->getJson('/account')->assertOk()->assertJson(['id' => $user->id]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function describe(?Viewer $viewer): array
    {
        if ($viewer === null) {
            return ['viewer' => null];
        }

        return [
            'did' => $viewer->did,
            'source' => $viewer->source->value,
            'method' => $viewer->method,
            'isService' => $viewer->isService(),
            'hasUser' => $viewer->user() !== null,
            'userId' => $viewer->user()?->getAuthIdentifier(),
        ];
    }

    protected function bearing(string $jwt): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$jwt);
    }

    protected function mint(string $issuer = self::CALLER, string $audience = self::AUDIENCE, ?string $method = self::METHOD): string
    {
        return $this->app->make(ServiceAuth::class)->mint($issuer, $audience, $method, $this->signer(), 'ES256K', 60);
    }

    /**
     * @return callable(string): string
     */
    protected function signer(): callable
    {
        $order = new BigInteger(self::ORDER, 16);

        return function (string $data) use ($order): string {
            $signature = $this->key->withSignatureFormat('Raw')->withHash('sha256')->sign($data);
            [$half] = $order->divide(new BigInteger(2));
            $s = $signature['s']->compare($half) > 0 ? $order->subtract($signature['s']) : $signature['s'];

            return str_pad($signature['r']->toBytes(), 32, "\0", STR_PAD_LEFT).str_pad($s->toBytes(), 32, "\0", STR_PAD_LEFT);
        };
    }

    protected function didKey(): string
    {
        $point = $this->key->getPublicKey()->getEncodedCoordinates();
        $x = substr($point, 1, 32);
        $y = substr($point, 33, 32);

        return 'did:key:z'.Base58::encode(DidKey::SECP256K1_PREFIX.chr(0x02 | (ord($y[31]) & 1)).$x);
    }
}

class GuardTestUser extends Authenticatable implements HasAtpSession
{
    protected $table = 'users';

    protected $guarded = [];

    public function getAtpDid(): ?string
    {
        return $this->did;
    }
}
