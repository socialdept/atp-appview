<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Ingest\RepoDiscovery;

class DiscoverCommandTest extends TestCase
{
    protected const RELAY = 'https://relay.test';
    protected const TAP = 'http://tap.test';

    protected function getPackageProviders($app): array
    {
        return [AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('atp-appview.ingest.discovery_url', self::RELAY);
        $app['config']->set('atp-appview.ingest.admin_url', self::TAP);
        $app['config']->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Discovery leaves out repos the blocklist keeps out.
        $this->artisan('migrate')->run();
    }

    public function test_it_pages_through_every_collection_and_adds_unique_repos_to_tap(): void
    {
        Http::fake([
            self::RELAY.'/*' => function (Request $request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(match ([$query['collection'], $query['cursor'] ?? null]) {
                    ['app.example.post', null] => ['repos' => [['did' => 'did:plc:a'], ['did' => 'did:plc:b']], 'cursor' => 'page2'],
                    ['app.example.post', 'page2'] => ['repos' => [['did' => 'did:plc:c']]],
                    ['app.example.like', null] => ['repos' => [['did' => 'did:plc:b'], ['did' => 'did:plc:d']]],
                });
            },
            self::TAP.'/*' => Http::response([], 200),
        ]);

        $this->artisan('appview:discover', ['collection' => ['app.example.post', 'app.example.like']])
            ->expectsOutputToContain('app.example.post')
            ->expectsOutputToContain('4 unique repos sent to Tap')
            ->assertSuccessful();

        $added = collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/repos/add'))
            ->flatMap(fn ($pair) => $pair[0]['dids'])
            ->all();

        $this->assertSame(['did:plc:a', 'did:plc:b', 'did:plc:c', 'did:plc:d'], $added);
    }

    public function test_repos_are_sent_to_tap_in_chunks(): void
    {
        Http::fake([
            self::RELAY.'/*' => Http::response(['repos' => array_map(fn ($i) => ['did' => "did:plc:{$i}"], range(1, 5))]),
            self::TAP.'/*' => Http::response([], 200),
        ]);

        $this->artisan('appview:discover', ['collection' => ['app.example.post'], '--chunk' => 2])->assertSuccessful();

        $chunks = collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/repos/add'))
            ->map(fn ($pair) => count($pair[0]['dids']))
            ->values()
            ->all();

        $this->assertSame([2, 2, 1], $chunks);
    }

    public function test_a_dry_run_counts_without_touching_tap(): void
    {
        Http::fake([
            self::RELAY.'/*' => Http::response(['repos' => [['did' => 'did:plc:a'], ['did' => 'did:plc:b']]]),
            self::TAP.'/*' => Http::response([], 200),
        ]);

        $this->artisan('appview:discover', ['collection' => ['app.example.post'], '--dry-run' => true])
            ->expectsOutputToContain('2 unique repos found on https://relay.test. Nothing was added to Tap.')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), self::TAP));
    }

    public function test_collections_default_to_config_and_source_can_be_overridden(): void
    {
        config()->set('atp-appview.ingest.collections', ['app.example.post']);
        Http::fake([
            'https://lightrail.test/*' => Http::response(['repos' => [['did' => 'did:plc:a']]]),
            self::TAP.'/*' => Http::response([], 200),
        ]);

        $this->artisan('appview:discover', ['--source' => 'https://lightrail.test'])->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://lightrail.test/xrpc/'.RepoDiscovery::METHOD)
            && str_contains($request->url(), 'collection=app.example.post'));
    }

    public function test_it_refuses_to_run_without_collections_or_with_a_wildcard(): void
    {
        $this->artisan('appview:discover')->assertFailed();
        $this->artisan('appview:discover', ['collection' => ['app.example.*']])->assertFailed();
    }

    public function test_a_rate_limit_is_retried(): void
    {
        Http::fake([
            self::RELAY.'/*' => Http::sequence()
                ->push(['error' => 'RateLimitExceeded'], 429)
                ->push(['repos' => [['did' => 'did:plc:a']]]),
            self::TAP.'/*' => Http::response([], 200),
        ]);
        Sleep::fake();

        $this->artisan('appview:discover', ['collection' => ['app.example.post']])->assertSuccessful();
    }

    public function test_a_repeated_cursor_stops_discovery_instead_of_looping(): void
    {
        Http::fake([
            self::RELAY.'/*' => Http::response(['repos' => [['did' => 'did:plc:a']], 'cursor' => 'same']),
            self::TAP.'/*' => Http::response([], 200),
        ]);

        $this->artisan('appview:discover', ['collection' => ['app.example.post']])
            ->expectsOutputToContain('repeated cursor')
            ->assertFailed();
    }
}
