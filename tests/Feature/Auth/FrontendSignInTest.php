<?php

namespace SocialDept\AtpAppview\Tests\Feature\Auth;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Auth\FrontendAuth;
use SocialDept\AtpAppview\Http\Controllers\Auth\OAuthController;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Auth\AuthorizationServerDiscovery;
use SocialDept\AtpClient\Auth\ClientAssertionManager;
use SocialDept\AtpClient\Auth\OAuthEngine;
use SocialDept\AtpClient\Auth\ServiceAuth;
use SocialDept\AtpClient\Data\AuthorizationServerMetadata;
use SocialDept\AtpClient\Data\ServiceAuthToken;
use SocialDept\AtpClient\Exceptions\ServiceAuthException;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Facades\Resolver;

class FrontendSignInTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const AUDIENCE = 'did:web:app.example';
    protected const METHOD = 'com.example.createSession';

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('auth.providers.users.model', SignInTestUser::class);
        $app['config']->set('atp-client.service_auth.audience', self::AUDIENCE);
        $app['config']->set('atp-appview.auth.frontend.session_method', self::METHOD);
        $app['config']->set('atp-appview.auth.frontend.providers', [
            ['key' => 'bluesky', 'name' => 'Bluesky', 'server' => 'https://bsky.social'],
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/login', fn () => 'login')->name('login');
        $router->middleware('web')->get('/login/complete', fn () => 'complete')->name('login.complete');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('did')->unique();
            $table->string('handle')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function prepared(string $issuer): array
    {
        return [
            'state' => 'state-1',
            'codeVerifier' => 'verifier',
            'codeChallenge' => 'challenge',
            'codeChallengeMethod' => 'S256',
            'scopes' => 'atproto transition:generic',
            'parEndpoint' => "{$issuer}/oauth/par",
            'authorizationEndpoint' => "{$issuer}/oauth/authorize",
            'issuer' => $issuer,
            'tokenEndpoint' => "{$issuer}/oauth/token",
            'redirectUri' => 'https://app.example/oauth/callback',
            'clientId' => 'https://app.example/client-metadata.json',
            'pdsEndpoint' => $issuer,
            'handle' => '',
        ];
    }

    protected function knowAlice(): void
    {
        Resolver::shouldReceive('resolveDid')->with(self::ALICE, false)->andReturn(DidDocument::fromArray([
            'id' => self::ALICE,
            'alsoKnownAs' => ['at://alice.test'],
            'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example']],
        ]));
        Resolver::shouldReceive('handleToDid')->with('alice.test')->andReturn(self::ALICE);

        $this->mock(AuthorizationServerDiscovery::class)->shouldReceive('discover')->with('https://pds.example')->andReturn(new AuthorizationServerMetadata(
            issuer: 'https://auth.example',
            authorizationEndpoint: 'https://auth.example/oauth/authorize',
            tokenEndpoint: 'https://auth.example/oauth/token',
            parEndpoint: 'https://auth.example/oauth/par',
            pdsEndpoint: 'https://pds.example',
            revocationEndpoint: 'https://auth.example/oauth/revoke',
        ));
    }

    protected function verifies(string $did): void
    {
        $this->mock(ServiceAuth::class, function ($mock) use ($did) {
            $mock->shouldReceive('fromHeader')->andReturn('jwt');
            $mock->shouldReceive('verify')->with('jwt', self::AUDIENCE, self::METHOD)
                ->andReturn(new ServiceAuthToken($did, self::AUDIENCE, self::METHOD, time() + 60));
        });
    }

    public function test_the_routes_register_in_frontend_mode_only(): void
    {
        $this->assertTrue(Route::has('atp.oauth.prepare'));
        $this->assertTrue(Route::has('atp.session.store'));
        $this->assertSame('/oauth/callback', route('atp.oauth.callback', absolute: false));

        config()->set('atp-appview.auth.mode', 'backend');
        $this->assertFalse(FrontendAuth::enabled());
    }

    public function test_preparing_a_provider_sign_in_keeps_the_verifier_on_the_server(): void
    {
        $this->mock(OAuthEngine::class)->shouldReceive('prepareAuthorization')->once()
            ->with('', null, 'https://bsky.social')
            ->andReturn($this->prepared('https://bsky.social'));

        $this->postJson(route('atp.oauth.prepare'), ['provider' => 'bluesky', 'create' => true])
            ->assertOk()
            ->assertJson(['state' => 'state-1', 'parEndpoint' => 'https://bsky.social/oauth/par', 'prompt' => 'create', 'loginHint' => null])
            ->assertJsonMissingPath('codeVerifier')
            ->assertSessionHas(OAuthController::FLOW_KEY.'.codeVerifier', 'verifier');
    }

    public function test_a_handle_or_did_sign_in_starts_at_the_accounts_pds_and_hints_it(): void
    {
        $this->mock(OAuthEngine::class, function ($mock) {
            $mock->shouldReceive('prepareAuthorization')->once()->with('alice.test')->andReturn($this->prepared('https://auth.example'));
            $mock->shouldReceive('prepareAuthorization')->once()->with(self::ALICE)->andReturn($this->prepared('https://auth.example'));
        });

        $this->postJson(route('atp.oauth.prepare'), ['handle' => ' @Alice.test '])
            ->assertOk()
            ->assertJson(['loginHint' => 'alice.test', 'prompt' => null])
            ->assertSessionHas(OAuthController::FLOW_KEY.'.handle', 'alice.test');

        $this->postJson(route('atp.oauth.prepare'), ['did' => self::ALICE])
            ->assertOk()
            ->assertJson(['loginHint' => self::ALICE])
            ->assertSessionHas(OAuthController::FLOW_KEY.'.did', self::ALICE);

        $this->postJson(route('atp.oauth.prepare'), ['did' => 'alice.test'])->assertStatus(422);
        $this->postJson(route('atp.oauth.prepare'), ['provider' => 'unknown'])->assertStatus(422);
    }

    public function test_an_account_that_cannot_be_found_says_so(): void
    {
        $this->mock(OAuthEngine::class)->shouldReceive('prepareAuthorization')->andThrow(new RuntimeException('nope'));

        $this->postJson(route('atp.oauth.prepare'), ['handle' => 'nobody.example'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Could not find nobody.example. Check the handle and try again.']);
    }

    public function test_client_assertions_are_only_signed_for_the_flow_in_progress_or_the_users_own_server(): void
    {
        $this->knowAlice();
        $this->mock(ClientAssertionManager::class)->shouldReceive('getAuthParams')
            ->andReturnUsing(fn ($issuer) => ['client_id' => 'x', 'client_assertion' => "signed-for-{$issuer}"]);

        $this->postJson(route('atp.oauth.client-assertion'), ['issuer' => 'https://auth.example'])->assertStatus(403);

        $this->withSession([OAuthController::FLOW_KEY => ['issuer' => 'https://auth.example']])
            ->postJson(route('atp.oauth.client-assertion'), ['issuer' => 'https://auth.example/'])
            ->assertOk()
            ->assertJson(['client_assertion' => 'signed-for-https://auth.example']);

        $this->withSession([OAuthController::FLOW_KEY => ['issuer' => 'https://auth.example']])
            ->postJson(route('atp.oauth.client-assertion'), ['issuer' => 'https://other.example'])
            ->assertStatus(403);

        $this->flushSession();

        // A signed-in user's own server is resolved from their DID, never
        // taken from the request.
        $user = SignInTestUser::create(['did' => self::ALICE]);
        $this->actingAs($user, 'web')->postJson(route('atp.oauth.client-assertion'), ['issuer' => 'https://auth.example'])->assertOk();
        $this->actingAs($user, 'web')->postJson(route('atp.oauth.client-assertion'), ['issuer' => 'https://other.example'])->assertStatus(403);
    }

    public function test_the_callback_checks_state_and_issuer_then_holds_the_code_for_the_browser(): void
    {
        $flow = [OAuthController::FLOW_KEY => ['state' => 'state-1', 'codeVerifier' => 'verifier', 'issuer' => 'https://auth.example', 'tokenEndpoint' => 'https://auth.example/oauth/token', 'redirectUri' => 'https://app.example/oauth/callback', 'clientId' => 'client', 'handle' => null, 'did' => null]];

        $this->withSession($flow)->get(route('atp.oauth.callback', ['code' => 'c', 'state' => 'other']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['handle' => 'That sign-in did not start here. Try again.']);

        $this->withSession($flow)->get(route('atp.oauth.callback', ['code' => 'c', 'state' => 'state-1', 'iss' => 'https://evil.example']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['handle' => 'That sign-in came back from the wrong server. Try again.']);

        $this->withSession($flow)->get(route('atp.oauth.callback', ['code' => 'c', 'state' => 'state-1', 'iss' => 'https://auth.example']))
            ->assertRedirect(route('login.complete'))
            ->assertSessionHas(OAuthController::EXCHANGE_KEY, 'https://auth.example');

        // The browser collects the flow once, and the code never appears in
        // a URL or a page.
        $this->postJson(route('atp.oauth.flow'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['code' => 'c', 'state' => 'state-1', 'codeVerifier' => 'verifier', 'did' => null]);

        $this->postJson(route('atp.oauth.flow'))->assertStatus(410);
    }

    public function test_a_callback_without_a_pending_sign_in_or_with_an_error_goes_back_to_login(): void
    {
        $this->get(route('atp.oauth.callback', ['code' => 'c', 'state' => 's']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['handle' => 'That sign-in link has expired. Try again.']);

        $this->get(route('atp.oauth.callback', ['error' => 'access_denied', 'error_description' => 'User denied the request']))
            ->assertSessionHasErrors(['handle' => 'User denied the request']);
    }

    public function test_an_account_is_only_resolved_during_a_sign_in_or_for_the_signed_in_user(): void
    {
        $this->knowAlice();

        $this->getJson(route('atp.oauth.account', ['did' => self::ALICE]))->assertStatus(403);

        $this->withSession([OAuthController::EXCHANGE_KEY => 'https://auth.example'])
            ->getJson(route('atp.oauth.account', ['did' => self::ALICE]))
            ->assertOk()
            ->assertExactJson(['did' => self::ALICE, 'pds' => 'https://pds.example', 'issuer' => 'https://auth.example', 'revocationEndpoint' => 'https://auth.example/oauth/revoke', 'handle' => 'alice.test']);

        $this->flushSession();

        $this->actingAs(SignInTestUser::create(['did' => self::ALICE]), 'web')
            ->getJson(route('atp.oauth.account', ['did' => self::ALICE]))
            ->assertOk();
    }

    public function test_a_service_auth_token_from_the_users_own_pds_starts_a_session(): void
    {
        $this->knowAlice();
        $this->verifies(self::ALICE);

        $this->withSession([OAuthController::EXCHANGE_KEY => 'https://auth.example'])
            ->postJson(route('atp.session.store'), [], ['Authorization' => 'Bearer jwt'])
            ->assertOk()
            ->assertExactJson(['did' => self::ALICE, 'handle' => 'alice.test'])
            ->assertSessionMissing(OAuthController::EXCHANGE_KEY);

        $user = SignInTestUser::sole();
        $this->assertSame('alice.test', $user->handle);
        $this->assertAuthenticatedAs($user, 'web');

        // Signing in again reuses the user and keeps the handle current.
        $user->forceFill(['handle' => 'old.test'])->save();
        $this->postJson(route('atp.session.store'), [], ['Authorization' => 'Bearer jwt'])->assertOk();
        $this->assertSame(1, SignInTestUser::count());
        $this->assertSame('alice.test', $user->fresh()->handle);
    }

    public function test_no_user_is_created_when_provisioning_is_off(): void
    {
        config()->set('atp-appview.auth.provision', 'none');
        $this->knowAlice();
        $this->verifies(self::ALICE);

        $this->postJson(route('atp.session.store'), [], ['Authorization' => 'Bearer jwt'])->assertStatus(403);

        $this->assertGuest('web');
        $this->assertSame(0, SignInTestUser::count());
    }

    public function test_a_session_is_refused_without_a_valid_token_or_from_a_service(): void
    {
        $this->mock(ServiceAuth::class, function ($mock) {
            $mock->shouldReceive('fromHeader')->andReturn('jwt');
            $mock->shouldReceive('verify')->once()->andThrow(new ServiceAuthException('Bad signature', 'BadJwtSignature'));
        });

        $this->postJson(route('atp.session.store'), [], ['Authorization' => 'Bearer jwt'])
            ->assertStatus(401)
            ->assertJson(['error' => 'BadJwtSignature']);

        $this->verifies('did:web:feed.example#bsky_fg');

        $this->postJson(route('atp.session.store'), [], ['Authorization' => 'Bearer jwt'])->assertStatus(401);

        $this->assertGuest('web');
        $this->assertSame(0, SignInTestUser::count());
    }

    public function test_a_session_needs_a_session_method(): void
    {
        config()->set('atp-appview.auth.frontend.session_method', null);

        $this->postJson(route('atp.session.store'), [], ['Authorization' => 'Bearer jwt'])->assertStatus(500);
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->actingAs(SignInTestUser::create(['did' => self::ALICE]), 'web')
            ->deleteJson(route('atp.session.destroy'))
            ->assertOk();

        $this->assertGuest('web');
    }

    public function test_the_browser_config_and_providers_leave_servers_out(): void
    {
        $this->assertSame(['sessionMethod' => self::METHOD, 'audience' => self::AUDIENCE, 'xrpcPrefix' => '/xrpc', 'syncRecord' => null, 'proxy' => false, 'realtime' => null, 'signOut' => null], FrontendAuth::browserConfig());
        $this->assertSame([['key' => 'bluesky', 'name' => 'Bluesky']], FrontendAuth::providers());
    }
}

class SignInTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
