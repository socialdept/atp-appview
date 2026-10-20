<?php

namespace SocialDept\AtpAppview\Tests\Feature\Push;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use SocialDept\AtpAppview\AtpAppviewServiceProvider;
use SocialDept\AtpAppview\Auth\FrontendAuth;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Notifications\NotificationPreferences;
use SocialDept\AtpAppview\Push\Push;
use SocialDept\AtpAppview\Push\WebPush;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpClient\Crypto\P256;
use SocialDept\AtpSchema\SchemaServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

/**
 * Web Push with PHP's own OpenSSL: encryption checked against RFC 8291's
 * example, VAPID tokens, subscriptions, and a notification arriving at a
 * push service in a form only the browser can read.
 */
class PushTest extends TestCase
{
    protected const ALICE = 'did:plc:alice';
    protected const BOB = 'did:plc:bob';
    protected const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    protected string $vapidPublic;

    protected function getPackageProviders($app): array
    {
        return [AtpSupportServiceProvider::class, SchemaServiceProvider::class, AtpClientServiceProvider::class, AtpAppviewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $key = P256::create();
        $jwk = $key->toJWK()->asPublic()->toArray();
        $this->vapidPublic = WebPush::base64url("\x04".WebPush::fromBase64url($jwk['x']).WebPush::fromBase64url($jwk['y']));

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('atp-schema.dns_resolution.enabled', false);
        $app['config']->set('atp-appview.xrpc.discover.path', null);
        $app['config']->set('atp-appview.xrpc.middleware', ['web']);
        $app['config']->set('atp-appview.features', [Features::notifications(), Features::push()]);
        $app['config']->set('atp-appview.notifications.reasons', ['com.example.like' => ['subject.uri' => 'like']]);
        $app['config']->set('atp-appview.push.public_key', $this->vapidPublic);
        $app['config']->set('atp-appview.push.private_key', $key->privateB64());
        $app['config']->set('atp-appview.push.subject', 'mailto:ops@example.com');
        $app['config']->set('atp-appview.push.xrpc', ['subscribe' => 'com.example.push.subscribe', 'unsubscribe' => 'com.example.push.unsubscribe']);
        $app['config']->set('auth.providers.users.model', PushUser::class);
        $app['config']->set('auth.guards.atp', ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('did');
            $table->rememberToken();
        });
    }

    /**
     * A browser's side of a subscription: its key pair and auth secret.
     *
     * @return array{key: \OpenSSLAsymmetricKey, p256dh: string, auth: string}
     */
    protected function browser(): array
    {
        [$key, $point] = WebPush::keyPair();

        return ['key' => $key, 'p256dh' => WebPush::base64url($point), 'auth' => WebPush::base64url(random_bytes(16))];
    }

    /**
     * Decrypt a message as the browser would.
     *
     * @param  array{key: \OpenSSLAsymmetricKey, p256dh: string, auth: string}  $browser
     */
    protected function decrypt(string $body, array $browser): string
    {
        $salt = substr($body, 0, 16);
        $idLength = ord($body[20]);
        $sender = substr($body, 21, $idLength);
        $record = substr($body, 21 + $idLength);
        $senderPem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$sender), 64, "\n")."-----END PUBLIC KEY-----\n";

        $userPublic = WebPush::fromBase64url($browser['p256dh']);
        $secret = openssl_pkey_derive(openssl_pkey_get_public($senderPem), $browser['key'], 32);
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0".$userPublic.$sender, WebPush::fromBase64url($browser['auth']));
        $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, substr($record, -16));

        return rtrim((string) $plain, "\x02");
    }

    public function test_encryption_matches_rfc_8291s_example(): void
    {
        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            WebPush::fromBase64url('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            WebPush::fromBase64url('BTBZMqHH6r4Tts7J_aSIgg'),
            [
                'private' => WebPush::fromBase64url('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
                'public' => WebPush::fromBase64url('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
            ],
            WebPush::fromBase64url('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            WebPush::base64url($body),
        );
    }

    public function test_vapid_tokens_are_signed_for_the_push_services_origin(): void
    {
        $header = WebPush::vapid(self::ENDPOINT, P256::load(config('atp-appview.push.private_key'))->toPEM(), WebPush::fromBase64url($this->vapidPublic), 'mailto:ops@example.com');

        preg_match('/^vapid t=([^,]+), k=(.+)$/', $header, $match);
        $this->assertSame($this->vapidPublic, $match[2]);

        $publicPem = P256::load(config('atp-appview.push.private_key'))->toJWK()->asPublic()->toPEM();
        $claims = JWT::decode($match[1], new Key($publicPem, 'ES256'));

        $this->assertSame('https://fcm.googleapis.com', $claims->aud);
        $this->assertSame('mailto:ops@example.com', $claims->sub);
        $this->assertGreaterThan(time(), $claims->exp);
    }

    public function test_only_https_endpoints_on_known_push_services_are_kept(): void
    {
        $browser = $this->browser();
        $push = app(Push::class);

        $push->subscribe(self::ALICE, self::ENDPOINT, $browser['p256dh'], $browser['auth']);
        $push->subscribe(self::ALICE, self::ENDPOINT, $browser['p256dh'], $browser['auth']);
        $push->subscribe(self::ALICE, 'https://updates.push.services.mozilla.com/wpush/v2/x', $browser['p256dh'], $browser['auth']);
        $this->assertCount(2, $push->subscriptions(self::ALICE), 'The same endpoint twice is kept once.');

        foreach (['http://fcm.googleapis.com/x', 'https://169.254.169.254/latest', 'https://evil.example/push'] as $endpoint) {
            try {
                $push->subscribe(self::ALICE, $endpoint, $browser['p256dh'], $browser['auth']);
                $this->fail("{$endpoint} should be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $push->unsubscribe(self::BOB, self::ENDPOINT);
        $this->assertCount(2, $push->subscriptions(self::ALICE), 'Only its own account unsubscribes a browser.');
        $push->unsubscribe(self::ALICE, self::ENDPOINT);
        $this->assertCount(1, $push->subscriptions(self::ALICE));
    }

    public function test_a_new_notification_reaches_the_browser_readable_only_by_it(): void
    {
        $browser = $this->browser();
        app(Push::class)->subscribe(self::ALICE, self::ENDPOINT, $browser['p256dh'], $browser['auth']);
        Http::fake([self::ENDPOINT => Http::response('', 201), '*' => Http::response([], 404)]);

        $this->like('l1');

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $request) => $request->url() === self::ENDPOINT)->values();
        $this->assertCount(1, $sent);
        $this->assertSame('aes128gcm', $sent[0]->header('Content-Encoding')[0]);
        $this->assertStringStartsWith('vapid t=', $sent[0]->header('Authorization')[0]);
        $this->assertSame('86400', $sent[0]->header('TTL')[0]);

        $payload = json_decode($this->decrypt($sent[0]->body(), $browser), true);
        $this->assertSame(['type' => 'notification', 'reason' => 'like'], array_intersect_key($payload, ['type' => 1, 'reason' => 1]));
        $this->assertSame(self::BOB, $payload['actor']['did']);
        $this->assertNotNull(DB::table('atp_push_subscriptions')->value('used_at'));
    }

    public function test_a_reason_not_wanted_pushed_is_not_and_a_gone_subscription_is_dropped(): void
    {
        $browser = $this->browser();
        app(Push::class)->subscribe(self::ALICE, self::ENDPOINT, $browser['p256dh'], $browser['auth']);
        Http::fake([self::ENDPOINT => Http::response('', 410), '*' => Http::response([], 404)]);

        app(NotificationPreferences::class)->set(self::ALICE, 'like', push: false);
        $this->like('l1');
        Http::assertNotSent(fn (Request $request) => $request->url() === self::ENDPOINT);

        app(NotificationPreferences::class)->set(self::ALICE, 'like', push: true);
        $this->like('l2');
        $this->assertSame(0, DB::table('atp_push_subscriptions')->count(), 'The push service said it is gone.');
    }

    public function test_browsers_subscribe_and_unsubscribe_through_xrpc_and_learn_the_key(): void
    {
        $browser = $this->browser();
        $alice = PushUser::create(['did' => self::ALICE]);

        $this->postJson('/xrpc/com.example.push.subscribe', [])->assertStatus(401);
        $this->actingAs($alice, 'web')->postJson('/xrpc/com.example.push.subscribe', ['endpoint' => 'https://evil.example/x', 'keys' => ['p256dh' => $browser['p256dh'], 'auth' => $browser['auth']]])->assertStatus(400);
        $this->actingAs($alice, 'web')->postJson('/xrpc/com.example.push.subscribe', ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => $browser['p256dh'], 'auth' => $browser['auth']]])->assertOk();
        $this->assertSame([self::ALICE], DB::table('atp_push_subscriptions')->pluck('did')->all());

        $this->actingAs($alice, 'web')->postJson('/xrpc/com.example.push.unsubscribe', ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, DB::table('atp_push_subscriptions')->count());

        $this->assertSame(['publicKey' => $this->vapidPublic, 'subscribe' => 'com.example.push.subscribe', 'unsubscribe' => 'com.example.push.unsubscribe'], FrontendAuth::browserConfig()['push']);
        $this->assertArrayHasKey('appview:push:keys', Artisan::all());
    }

    protected function like(string $rkey): void
    {
        $post = 'at://'.self::ALICE.'/com.example.thing/p';

        Pipeline::fromConfig(app())->run(new Batch([
            new RecordEvent(1, self::ALICE, 'com.example.thing', 'p', RecordAction::Create, 'bafy-p', '3l2aaaaaaaaaa', ['text' => 'hi'], true),
            new RecordEvent(2, self::BOB, 'com.example.like', $rkey, RecordAction::Create, "bafy-{$rkey}", '3l2aaaaaaaaaa', ['subject' => ['uri' => $post]], true),
        ]));
    }
}

class PushUser extends Authenticatable implements HasAtpSession
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getAtpDid(): ?string
    {
        return $this->did;
    }
}
