<?php

namespace SocialDept\AtpAppview\Tests\Feature\Auth;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Auth\FrontendAuth;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

class SignInViewsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('auth.providers.users.model', ViewsTestUser::class);

        // As an app would in its own provider. The callbacks take whatever
        // the container can inject, and return anything a route may return.
        FrontendAuth::loginView(fn (Request $request) => ['page' => 'login', 'providers' => FrontendAuth::providers(), 'path' => $request->path()]);
        FrontendAuth::completeView(fn () => response('finishing', 200));
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
    }

    protected function tearDown(): void
    {
        FrontendAuth::flushViews();

        parent::tearDown();
    }

    public function test_the_package_serves_the_pages_an_app_renders(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertJson(['page' => 'login', 'providers' => [['key' => 'bluesky', 'name' => 'Bluesky']], 'path' => 'login']);

        $this->get(route('login.complete'))->assertOk()->assertSee('finishing');
    }

    public function test_the_login_page_is_for_guests(): void
    {
        $this->actingAs(ViewsTestUser::create(['did' => 'did:plc:alice']), 'web')
            ->get(route('login'))
            ->assertRedirect();

        $this->get(route('login.complete'))->assertOk();
    }

    public function test_a_failed_callback_returns_to_the_package_login_page(): void
    {
        $this->get(route('atp.oauth.callback', ['code' => 'c', 'state' => 's']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('handle');
    }

    public function test_without_callbacks_the_app_keeps_its_own_routes(): void
    {
        FrontendAuth::flushViews();
        $router = $this->app['router'];
        $before = count($router->getRoutes());

        $this->app->make(FrontendAuth::class)->registerViews();

        $this->assertCount($before, $router->getRoutes());
        $this->assertTrue(Route::has('login'));
    }
}

class ViewsTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
