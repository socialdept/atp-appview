<?php

namespace SocialDept\AtpAppview\Tests\Feature\Xrpc;

use Illuminate\Support\Facades\Http;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Facades\Xrpc;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Tests\TestCase;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Contracts\CredentialProvider;
use SocialDept\AtpClient\Data\Credentials;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpClient\Testing\FakeResponse;
use SocialDept\AtpClient\Testing\RecordedRequest;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;
use SocialDept\AtpSupport\Data\DidDocument;
use SocialDept\AtpSupport\Facades\Resolver;

/**
 * One way to call XRPC methods: this AppView's own answered in-process,
 * another service's through the account's PDS, or directly when calling
 * anonymously.
 */
class XrpcClientTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const LIST = 'com.example.notification.listNotifications';
    protected const TIMELINE = 'app.bsky.feed.getTimeline';
    protected const BSKY = 'did:web:api.bsky.app#bsky_appview';

    protected bool $holdsSession = true;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('atp-schema.sources', [__DIR__.'/../../Fixtures/lexicons']);
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.collections', ['com.example.listThings' => 'com.example.thing']);
        $app['config']->set('atp-client.service_auth.audience', 'did:web:appview.example#atproto_appview');
        $app['config']->set('atp-appview.notifications.reasons', ['com.example.like' => ['subject.uri' => 'like']]);
        $app['config']->set('atp-appview.notifications.xrpc.list', self::LIST);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Profiles may be fetched for actors. Only the service below answers.
        Http::fake(['https://api.bsky.app/*' => Http::response(['ok' => true]), '*' => Http::response([], 404)]);
        $this->artisan('migrate')->run();

        $credentials = $this->createStub(CredentialProvider::class);
        $credentials->method('getCredentials')->willReturnCallback(fn (string $did) => $this->holdsSession && $did === self::ALICE
            ? new Credentials(did: $did, accessToken: 'a', refreshToken: 'r', expiresAt: now()->addHour())
            : null);
        $this->app->instance(CredentialProvider::class, $credentials);
    }

    protected function index(string $did, string $collection, string $rkey, array $record): string
    {
        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(1, $did, $collection, $rkey, RecordAction::Create, "bafy-{$rkey}", '3l2aaaaaaaaaa', $record, true),
        ]));

        return "at://{$did}/{$collection}/{$rkey}";
    }

    public function test_own_methods_are_answered_in_process_as_the_viewer(): void
    {
        $thing = $this->index(self::ALICE, 'com.example.thing', 'a', ['text' => 'a']);
        $this->index(self::BOB, 'com.example.like', 'l', ['subject' => ['uri' => $thing]]);

        $page = Xrpc::as(self::ALICE)->query(self::LIST, ['limit' => 10]);

        $this->assertSame('like', $page['notifications'][0]['reason']);
        $this->assertSame(1, $page['notifications'][0]['count']);
        $this->assertSame([], Xrpc::as(self::BOB)->query(self::LIST)['notifications'], 'Each viewer reads their own.');

        // The AppView's own audience named as the service is still in-process.
        $this->assertSame($page, Xrpc::as(self::ALICE)->service('did:web:appview.example#atproto_appview')->query(self::LIST, ['limit' => 10]));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), self::LIST));
    }

    public function test_in_process_calls_keep_the_methods_auth_and_lexicon(): void
    {
        $this->index(self::ALICE, 'com.example.thing', 'a', ['text' => 'a']);

        $this->assertCount(1, Xrpc::query('com.example.listThings')['records'], 'A public query needs no viewer.');

        $this->assertXrpcError('AuthenticationRequired', fn () => Xrpc::query(self::LIST));
        $this->assertXrpcError('InvalidRequest', fn () => Xrpc::query('com.example.listThings', ['limit' => 50]));
        $this->assertXrpcError('InvalidRequest', fn () => Xrpc::as(self::ALICE)->procedure(self::LIST));
        $this->assertXrpcError('MethodNotImplemented', fn () => Xrpc::query('com.example.nothing'));
    }

    public function test_another_service_is_called_through_the_viewers_pds(): void
    {
        $atp = Atp::fake();
        $atp->stub(self::TIMELINE, ['feed' => [['post' => 'p']]]);

        $result = Xrpc::as(self::ALICE)->service(self::BSKY)->query(self::TIMELINE, ['limit' => 2, 'langs' => ['en', 'pt']]);

        $this->assertSame(['feed' => [['post' => 'p']]], $result);
        $atp->assertCalledWith(self::TIMELINE, fn (RecordedRequest $request) => $request->method === 'FORWARD_GET'
            && $request->did === self::ALICE
            && $request->params['_query'] === 'limit=2&langs=en&langs=pt'
            && $request->params['_headers']['atproto-proxy'] === self::BSKY);
    }

    public function test_errors_from_another_service_keep_their_name(): void
    {
        Atp::fake()->stub(self::TIMELINE, FakeResponse::error('BlockedActor', 'Blocked.', 400));

        $this->assertXrpcError('BlockedActor', fn () => Xrpc::as(self::ALICE)->service(self::BSKY)->query(self::TIMELINE));
    }

    public function test_without_a_server_held_session_another_service_is_left_to_the_browser(): void
    {
        $this->holdsSession = false;
        Atp::fake();

        $this->assertXrpcError('SessionRequired', fn () => Xrpc::as(self::ALICE)->service(self::BSKY)->query(self::TIMELINE));
    }

    public function test_anonymous_calls_go_straight_to_the_service_from_its_did_document(): void
    {
        Resolver::shouldReceive('resolveDid')->with('did:web:api.bsky.app')->andReturn(DidDocument::fromArray([
            'id' => 'did:web:api.bsky.app',
            'service' => [['id' => '#bsky_appview', 'type' => 'BskyAppView', 'serviceEndpoint' => 'https://api.bsky.app']],
        ]));

        $this->assertSame(['ok' => true], Xrpc::service(self::BSKY)->query('app.bsky.actor.getProfile', ['actor' => 'alice.test']));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=alice.test');
    }

    protected function assertXrpcError(string $error, \Closure $call): void
    {
        try {
            $call();
        } catch (XrpcException $e) {
            $this->assertSame($error, $e->error);

            return;
        }

        $this->fail("Expected the {$error} error.");
    }
}
