<?php

namespace SocialDept\AtpAppview\Tests\Feature\Xrpc;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Xrpc\XrpcRoutes;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * An application on Laravel's default database cache store, before its first
 * migrate. Booting registers XRPC routes, and that must not read lexicons
 * through a cache whose table does not exist yet.
 */
class FreshInstallTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'database');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.collections', ['com.example.listThings' => 'com.example.thing']);
    }

    public function test_a_fresh_app_boots_and_migrates_before_any_table_exists(): void
    {
        // Booting has already registered the routes, without a cache table.
        $this->assertTrue(Route::has('atp-appview.xrpc.com.example.listThings'));

        $this->artisan('migrate')->assertSuccessful();

        // Laravel's own tables, cache included, as an application has them.
        $this->loadLaravelMigrations();

        $this->getJson('/xrpc/com.example.listThings')->assertOk()->assertJson(['records' => []]);
    }

    public function test_registering_routes_runs_no_queries(): void
    {
        $this->artisan('migrate')->run();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });

        // As on every request that boots the app, XRPC or not.
        app(XrpcRoutes::class)->register();

        $this->assertSame([], $queries, 'Loading lexicons at boot read the cache once per method.');
    }

    public function test_a_query_called_with_post_says_which_verb_to_use(): void
    {
        $this->artisan('migrate')->run();
        $this->loadLaravelMigrations();

        $this->postJson('/xrpc/com.example.listThings')
            ->assertStatus(400)
            ->assertJson(['error' => 'InvalidRequest', 'message' => 'com.example.listThings is a query, call it with GET.']);
    }
}
