<?php

namespace SocialDept\AtpAppview\Tests\Feature\Auth;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Auth\AccountResolver;
use SocialDept\AtpAppview\Auth\FrontendAuth;
use SocialDept\AtpAppview\Auth\SignOut;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Auth\ClientAssertionManager;
use SocialDept\AtpClient\Contracts\CredentialProvider;
use SocialDept\AtpClient\Contracts\KeyStore;
use SocialDept\AtpClient\Data\AccessToken;
use SocialDept\AtpClient\Data\DPoPKey;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpClient\Providers\ArrayCredentialProvider;
use SocialDept\AtpClient\Providers\SessionCredentialProvider;
use SocialDept\AtpClient\Testing\FakeOAuthEngine;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class SignOutTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('auth.providers.users.model', SignOutTestUser::class);
        $app['config']->set('atp-client.client.client_id', 'https://app.example/client-metadata.json');
        $app['config']->set('atp-appview.auth.frontend.session_method', 'com.example.createSession');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->post('/logout', function (Request $request, SignOut $signOut) {
            $result = $signOut($request);

            return response()->json(['revoked' => $result?->revoked, 'failure' => $result?->failure?->value]);
        });
        $router->middleware('web')->get('/page', fn () => FrontendAuth::browserConfig());
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

    protected function alice(): SignOutTestUser
    {
        return SignOutTestUser::create(['did' => self::ALICE]);
    }

    public function test_in_frontend_mode_the_next_page_tells_the_browser_to_sign_out(): void
    {
        $this->actingAs($this->alice(), 'web')->postJson('/logout')
            ->assertOk()
            ->assertJson(['revoked' => null])
            ->assertSessionHas(SignOut::FLASH_KEY);

        $this->assertGuest('web');

        $signOut = $this->getJson('/page')->json('signOut');

        $this->assertIsString($signOut);
        $this->assertNotSame('', $signOut);

        // Flashed, so only the page right after the sign-out carries it.
        $this->assertNull($this->getJson('/page')->json('signOut'));
    }

    public function test_it_signs_out_of_the_guard_it_is_given(): void
    {
        config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
        $this->app['router']->middleware('web')->post('/admin/logout', fn (Request $request, SignOut $signOut) => $signOut($request, 'admin'));

        $this->actingAs($this->alice(), 'admin')->post('/admin/logout')->assertSessionHas(SignOut::FLASH_KEY);

        $this->assertGuest('admin');
    }

    public function test_the_browsers_own_sign_out_does_not_ask_it_to_sign_out_again(): void
    {
        $this->actingAs($this->alice(), 'web')->deleteJson(route('atp.session.destroy'))
            ->assertOk()
            ->assertSessionMissing(SignOut::FLASH_KEY)
            ->assertSessionMissing(SignOut::REVOKING_KEY);

        $this->assertGuest('web');
        $this->assertNull($this->getJson('/page')->json('signOut'));
    }

    public function test_a_guest_signing_out_tells_the_browser_nothing(): void
    {
        $this->postJson('/logout')->assertOk()->assertSessionMissing(SignOut::FLASH_KEY);
    }

    public function test_the_browser_gets_one_client_assertion_to_revoke_its_tokens(): void
    {
        $this->mock(AccountResolver::class)->shouldReceive('issuer')->with(self::ALICE)->andReturn('https://auth.example');
        $this->mock(ClientAssertionManager::class)->shouldReceive('getAuthParams')
            ->andReturnUsing(fn ($issuer) => ['client_id' => 'x', 'client_assertion' => "signed-for-{$issuer}"]);

        $this->actingAs($this->alice(), 'web')->postJson('/logout')->assertSessionHas(SignOut::REVOKING_KEY);

        $this->postJson(route('atp.oauth.client-assertion'), ['issuer' => 'https://auth.example'])
            ->assertOk()
            ->assertJson(['client_assertion' => 'signed-for-https://auth.example']);

        $this->postJson(route('atp.oauth.client-assertion'), ['issuer' => 'https://auth.example'])->assertStatus(403);
    }

    public function test_the_client_assertion_for_a_sign_out_expires(): void
    {
        $this->mock(AccountResolver::class)->shouldReceive('issuer')->with(self::ALICE)->andReturn('https://auth.example');
        $this->mock(ClientAssertionManager::class)->shouldReceive('getAuthParams')->andReturn(['client_id' => 'x']);

        $this->actingAs($this->alice(), 'web')->postJson('/logout');
        $this->travel(11)->minutes();

        $this->postJson(route('atp.oauth.client-assertion'), ['issuer' => 'https://auth.example'])->assertStatus(403);
    }

    public function test_in_backend_mode_the_servers_session_is_revoked_and_forgotten(): void
    {
        config()->set('atp-appview.auth.mode', 'backend');
        [$credentials, $keys] = $this->serverHoldsSession();
        Http::fake([
            'https://auth.example/.well-known/oauth-authorization-server' => Http::response($this->authServer()),
            'https://auth.example/oauth/revoke' => Http::response('', 200),
        ]);

        $this->actingAs($this->alice(), 'web')->postJson('/logout')
            ->assertOk()
            ->assertJson(['revoked' => true, 'failure' => null])
            ->assertSessionMissing(SignOut::FLASH_KEY);

        $this->assertGuest('web');
        $this->assertNull($credentials->getCredentials(self::ALICE));
        $this->assertSame([], $keys->keys);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://auth.example/oauth/revoke'
            && $request['token'] === 'refresh-token');
    }

    public function test_in_backend_mode_tokens_kept_in_the_session_are_revoked_before_it_ends(): void
    {
        config()->set('atp-appview.auth.mode', 'backend');
        $this->serverHoldsSession(new SessionCredentialProvider());
        Http::fake([
            'https://auth.example/.well-known/oauth-authorization-server' => Http::response($this->authServer()),
            'https://auth.example/oauth/revoke' => Http::response('', 200),
        ]);

        $this->actingAs($this->alice(), 'web')->postJson('/logout')->assertJson(['revoked' => true]);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://auth.example/oauth/revoke');
    }

    public function test_in_backend_mode_a_failed_revocation_still_signs_out(): void
    {
        config()->set('atp-appview.auth.mode', 'backend');
        [$credentials] = $this->serverHoldsSession();
        Http::fake([
            'https://auth.example/.well-known/oauth-authorization-server' => Http::response($this->authServer()),
            'https://auth.example/oauth/revoke' => Http::response('Bad gateway', 502),
        ]);

        $this->actingAs($this->alice(), 'web')->postJson('/logout')
            ->assertOk()
            ->assertJson(['revoked' => false, 'failure' => 'unreachable']);

        $this->assertGuest('web');
        $this->assertNull($credentials->getCredentials(self::ALICE));
    }

    public function test_in_backend_mode_it_signs_out_through_atp_oauth(): void
    {
        config()->set('atp-appview.auth.mode', 'backend');
        $fake = Atp::fake();

        $this->actingAs($this->alice(), 'web')->postJson('/logout')->assertJson(['revoked' => true]);

        $this->assertSame([['revoke', ['did' => self::ALICE]]], $fake->oauth()->recordedCalls());
    }

    /**
     * @return array{CredentialProvider, object}
     */
    protected function serverHoldsSession(?CredentialProvider $credentials = null): array
    {
        $credentials ??= new ArrayCredentialProvider();
        $credentials->storeCredentials(self::ALICE, new AccessToken(
            accessJwt: 'access-token',
            refreshJwt: 'refresh-token',
            did: self::ALICE,
            expiresAt: now()->addHour(),
            issuer: 'https://auth.example',
        ));
        $this->app->instance(CredentialProvider::class, $credentials);

        $keys = new class () implements KeyStore {
            public array $keys = [];

            public function store(string $sessionId, DPoPKey $key): void
            {
                $this->keys[$sessionId] = $key;
            }

            public function get(string $sessionId): ?DPoPKey
            {
                return $this->keys[$sessionId] ?? null;
            }

            public function delete(string $sessionId): void
            {
                unset($this->keys[$sessionId]);
            }

            public function exists(string $sessionId): bool
            {
                return isset($this->keys[$sessionId]);
            }
        };
        $keys->store('session_'.hash('sha256', self::ALICE), FakeOAuthEngine::fakeDPoPKey());
        $this->app->instance(KeyStore::class, $keys);

        return [$credentials, $keys];
    }

    protected function authServer(): array
    {
        return [
            'issuer' => 'https://auth.example',
            'authorization_endpoint' => 'https://auth.example/oauth/authorize',
            'token_endpoint' => 'https://auth.example/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://auth.example/oauth/par',
            'revocation_endpoint' => 'https://auth.example/oauth/revoke',
        ];
    }
}

class SignOutTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
