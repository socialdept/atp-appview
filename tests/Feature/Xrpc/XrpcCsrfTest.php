<?php

namespace SocialDept\AtpAppview\Tests\Feature\Xrpc;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Http\Middleware\VerifyXrpcCsrf;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * XRPC routes behind the web group. A PDS calling a procedure sends a bearer
 * token and no cookie, so the CSRF check must not refuse it, while a request
 * riding on a session cookie must still be checked.
 */
class XrpcCsrfTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('session.driver', 'array');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', __DIR__.'/../../Fixtures/Xrpc');
        $app['config']->set('atp-appview.xrpc.discover.namespace', 'SocialDept\\AtpAppview\\Tests\\Fixtures\\Xrpc');
        $app['config']->set('atp-appview.xrpc.middleware', ['web']);

        // A web group whose CSRF check runs under PHPUnit, and without cookie
        // encryption, so test cookies arrive as written.
        $app['router']->middlewareGroup('web', [StartSession::class, ShareErrorsFromSession::class, StrictCsrf::class, SubstituteBindings::class]);
    }

    public function test_xrpc_routes_swap_the_web_groups_csrf_check_for_their_own(): void
    {
        foreach (['atp-appview.xrpc.com.example.createThing', 'atp-appview.xrpc.fallback'] as $name) {
            $middleware = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name));

            $this->assertContains(VerifyXrpcCsrf::class.':'.StrictCsrf::class, $middleware, $name);
            $this->assertNotContains(StrictCsrf::class, $middleware, $name);
        }
    }

    public function test_a_procedure_called_with_a_bearer_token_is_not_refused_for_csrf(): void
    {
        $this->withCredentials()->withUnencryptedCookie(config('session.cookie'), $this->sessionWithToken('t'))
            ->withHeader('Authorization', 'Bearer service-auth-jwt')
            ->postJson('/xrpc/com.example.createThing', ['text' => 'hello'])
            ->assertOk()
            ->assertExactJson(['created' => 'hello']);
    }

    public function test_a_request_without_a_session_cookie_is_not_refused_for_csrf(): void
    {
        $this->postJson('/xrpc/com.example.createThing', ['text' => 'hello'])->assertOk();
        $this->postJson('/xrpc/com.example.nope')->assertStatus(501);
    }

    public function test_a_request_riding_on_a_session_cookie_needs_its_csrf_token(): void
    {
        $session = $this->sessionWithToken('right-token');

        $this->withCredentials()->withUnencryptedCookie(config('session.cookie'), $session)
            ->postJson('/xrpc/com.example.createThing', ['text' => 'hello'])
            ->assertStatus(419)
            ->assertJson(['error' => 'CsrfTokenMismatch']);

        $this->withCredentials()->withUnencryptedCookie(config('session.cookie'), $session)
            ->withHeader('X-CSRF-TOKEN', 'right-token')
            ->postJson('/xrpc/com.example.createThing', ['text' => 'hello'])
            ->assertOk();
    }

    public function test_routes_without_a_csrf_check_get_none(): void
    {
        config()->set('atp-appview.xrpc.middleware', []);
        Route::setRoutes(new \Illuminate\Routing\RouteCollection());
        app(\SocialDept\AtpAppview\Xrpc\XrpcRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $middleware = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('atp-appview.xrpc.com.example.createThing'));

        $this->assertSame([], array_values(array_filter($middleware, fn ($m) => str_starts_with($m, VerifyXrpcCsrf::class))));
    }

    /**
     * A started session holding a CSRF token, returning its id for the cookie.
     */
    protected function sessionWithToken(string $token): string
    {
        $session = app('session')->driver();
        $session->setId(str_repeat('a', 40));
        $session->start();
        $session->put('_token', $token);
        $session->save();

        return $session->getId();
    }
}

class StrictCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
