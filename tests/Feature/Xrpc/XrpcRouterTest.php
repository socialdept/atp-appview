<?php

namespace SocialDept\AtpAppview\Tests\Feature\Xrpc;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as DbSchema;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Tests\Fixtures\Xrpc\CreateThing;
use SocialDept\AtpAppview\Tests\Fixtures\Xrpc\GetThing;
use SocialDept\AtpAppview\Tests\Fixtures\Xrpc\WhoAmI;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpAppview\Xrpc\XrpcRegistry;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class XrpcRouterTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', __DIR__.'/../../Fixtures/Xrpc');
        $app['config']->set('atp-appview.xrpc.discover.namespace', 'SocialDept\\AtpAppview\\Tests\\Fixtures\\Xrpc');
        $app['config']->set('atp-appview.xrpc.middleware', ['web']);
        $app['config']->set('auth.providers.users.model', RouterTestUser::class);
        $app['config']->set('auth.guards.atp', ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web']);
    }

    public function test_handlers_are_discovered_with_their_type_from_the_lexicon(): void
    {
        $methods = $this->app->make(XrpcRegistry::class)->all();

        $this->assertSame(['com.example.createThing', 'com.example.getThing', 'com.example.whoAmI'], array_keys($methods));
        $this->assertSame('GET', $methods['com.example.getThing']->httpMethod());
        $this->assertSame('POST', $methods['com.example.createThing']->httpMethod());
        $this->assertSame(GetThing::class, $methods['com.example.getThing']->handler);
        $this->assertNull($methods['com.example.whoAmI']->lexicon);
    }

    public function test_query_parameters_are_typed_as_the_lexicon_declares(): void
    {
        $this->getJson('/xrpc/com.example.getThing?actor=alice.example&limit=25&reverse=true&uris=at%3A%2F%2Fa&uris=at%3A%2F%2Fb')
            ->assertOk()
            ->assertExactJson([
                'params' => ['actor' => 'alice.example', 'limit' => 25, 'reverse' => true, 'uris' => ['at://a', 'at://b']],
                'viewer' => null,
            ]);
    }

    public function test_a_missing_required_parameter_is_an_invalid_request(): void
    {
        $this->getJson('/xrpc/com.example.getThing?limit=5')
            ->assertStatus(400)
            ->assertJson(['error' => 'InvalidRequest', 'message' => 'Missing required parameter: actor.']);
    }

    public function test_a_parameter_of_the_wrong_type_is_an_invalid_request(): void
    {
        $this->getJson('/xrpc/com.example.getThing?actor=a&limit=lots')->assertStatus(400)->assertJson(['error' => 'InvalidRequest']);
        $this->getJson('/xrpc/com.example.getThing?actor=a&reverse=yes')->assertStatus(400)->assertJson(['error' => 'InvalidRequest']);
    }

    public function test_a_parameter_outside_its_lexicon_constraints_is_an_invalid_request(): void
    {
        $response = $this->getJson('/xrpc/com.example.getThing?actor=a&limit=500')->assertStatus(400)->assertJson(['error' => 'InvalidRequest']);

        $this->assertStringContainsString('limit', $response->json('message'));
        $this->getJson('/xrpc/com.example.getThing?actor=a&limit=100')->assertOk();
    }

    public function test_procedure_input_is_validated_and_handed_to_the_handler(): void
    {
        $this->postJson('/xrpc/com.example.createThing', ['text' => 'hello'])
            ->assertOk()
            ->assertExactJson(['created' => 'hello']);

        $this->postJson('/xrpc/com.example.createThing', ['text' => str_repeat('x', 50)])
            ->assertStatus(400)
            ->assertJson(['error' => 'InvalidRequest']);

        $this->postJson('/xrpc/com.example.createThing', [])
            ->assertStatus(400)
            ->assertJson(['error' => 'InvalidRequest']);
    }

    public function test_an_xrpc_exception_from_a_handler_becomes_an_xrpc_error(): void
    {
        $this->postJson('/xrpc/com.example.createThing', ['text' => 'taken'])
            ->assertStatus(409)
            ->assertExactJson(['error' => 'AlreadyExists', 'message' => 'That thing already exists.']);
    }

    public function test_a_required_method_refuses_anonymous_requests_and_injects_the_viewer(): void
    {
        DbSchema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('did');
            $table->rememberToken();
        });
        $user = RouterTestUser::create(['did' => 'did:plc:alice']);

        $this->getJson('/xrpc/com.example.whoAmI')->assertStatus(401)->assertJson(['error' => 'AuthenticationRequired']);

        $this->actingAs($user, 'web')->getJson('/xrpc/com.example.whoAmI')->assertOk()->assertExactJson(['did' => 'did:plc:alice']);
    }

    public function test_an_unknown_method_is_not_implemented(): void
    {
        $this->getJson('/xrpc/com.example.nothingHere')
            ->assertStatus(501)
            ->assertJson(['error' => 'MethodNotImplemented']);
    }

    public function test_a_known_method_called_with_the_wrong_http_method_says_which_to_use(): void
    {
        $this->postJson('/xrpc/com.example.getThing?actor=a')
            ->assertStatus(400)
            ->assertJson(['error' => 'InvalidRequest', 'message' => 'com.example.getThing is a query, call it with GET.']);
    }

    public function test_the_applications_own_xrpc_routes_take_precedence_over_the_fallback(): void
    {
        Route::get('/xrpc/com.example.custom', fn () => ['custom' => true]);

        $this->getJson('/xrpc/com.example.custom')->assertOk()->assertExactJson(['custom' => true]);
    }

    public function test_explicit_handlers_and_the_routes_command(): void
    {
        $this->artisan('appview:routes')
            ->expectsOutputToContain('com.example.getThing')
            ->expectsOutputToContain(WhoAmI::class)
            ->assertSuccessful();

        $this->assertContains(CreateThing::class, array_map(fn ($m) => $m->handler, $this->app->make(XrpcRegistry::class)->all()));
    }
}

class RouterTestUser extends Authenticatable implements HasAtpSession
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getAtpDid(): ?string
    {
        return $this->did;
    }
}
