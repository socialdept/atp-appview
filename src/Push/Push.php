<?php

namespace SocialDept\AtpAppview\Push;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use SocialDept\AtpAppview\Features\Push as PushFeature;
use SocialDept\AtpClient\Crypto\P256;
use Throwable;

/**
 * Web Push to the browsers each account turned it on in. Subscriptions are
 * kept in atp_push_subscriptions, messages encrypted and signed by WebPush,
 * and a subscription its push service no longer knows is dropped.
 *
 * Needs the push feature (Features::push()) and VAPID keys in
 * atp-appview.push. Without keys nothing is sent.
 */
class Push
{
    /**
     * Whether VAPID keys and a subject are set, so messages can be sent.
     */
    public static function configured(): bool
    {
        return PushFeature::on()
            && filled(config('atp-appview.push.public_key'))
            && filled(config('atp-appview.push.private_key'))
            && filled(config('atp-appview.push.subject'));
    }

    /**
     * The application server key a browser subscribes with, base64url.
     */
    public function publicKey(): ?string
    {
        PushFeature::ensureOn();

        return config('atp-appview.push.public_key') ?: null;
    }

    /**
     * Keep a browser's subscription for an account, replacing an older one
     * for the same endpoint.
     *
     * @throws InvalidArgumentException when the endpoint or keys are not usable
     */
    public function subscribe(string $did, string $endpoint, string $p256dh, string $auth, ?string $userAgent = null): void
    {
        PushFeature::ensureOn();

        self::checkEndpoint($endpoint);

        if (strlen(WebPush::fromBase64url($p256dh)) !== 65 || strlen(WebPush::fromBase64url($auth)) !== 16) {
            throw new InvalidArgumentException('A subscription needs its p256dh and auth keys, base64url.');
        }

        $this->connection()->table('atp_push_subscriptions')->upsert([[
            'did' => $did,
            'endpoint' => $endpoint,
            'endpoint_hash' => sha1($endpoint),
            'p256dh' => $p256dh,
            'auth' => $auth,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            'created_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ]], ['endpoint_hash'], ['did', 'endpoint', 'p256dh', 'auth', 'user_agent', 'created_at']);
    }

    /**
     * Forget a browser's subscription. Only its own account can.
     */
    public function unsubscribe(string $did, string $endpoint): void
    {
        PushFeature::ensureOn();

        $this->connection()->table('atp_push_subscriptions')->where('did', $did)->where('endpoint_hash', sha1($endpoint))->delete();
    }

    /**
     * @return Collection<int, object>
     */
    public function subscriptions(string $did): Collection
    {
        PushFeature::ensureOn();

        return $this->connection()->table('atp_push_subscriptions')->where('did', $did)->orderBy('id')->get();
    }

    /**
     * Push a payload to every browser of an account. Subscriptions the push
     * service says are gone are dropped.
     *
     * @param  array<string, mixed>  $payload
     * @return int How many browsers took it
     */
    public function send(string $did, array $payload): int
    {
        PushFeature::ensureOn();

        if (! self::configured()) {
            return 0;
        }

        $publicKey = WebPush::fromBase64url((string) config('atp-appview.push.public_key'));
        $privatePem = P256::load((string) config('atp-appview.push.private_key'))->toPEM();
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sent = 0;

        foreach ($this->subscriptions($did) as $subscription) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => WebPush::vapid($subscription->endpoint, $privatePem, $publicKey, (string) config('atp-appview.push.subject')),
                    'TTL' => (string) (int) config('atp-appview.push.ttl', 86400),
                    'Urgency' => (string) config('atp-appview.push.urgency', 'normal'),
                    'Content-Encoding' => 'aes128gcm',
                ])->withBody(
                    WebPush::encrypt($body, WebPush::fromBase64url($subscription->p256dh), WebPush::fromBase64url($subscription->auth)),
                    'application/octet-stream',
                )->timeout(10)->post($subscription->endpoint);
            } catch (Throwable $e) {
                Log::warning('Web Push failed to reach a push service.', ['did' => $did, 'error' => $e->getMessage()]);

                continue;
            }

            if ($response->successful()) {
                $this->connection()->table('atp_push_subscriptions')->where('id', $subscription->id)->update(['used_at' => Carbon::now('UTC')->format('Y-m-d H:i:s')]);
                $sent++;
            } elseif (in_array($response->status(), [404, 410], true)) {
                // The browser unsubscribed or the subscription expired.
                $this->connection()->table('atp_push_subscriptions')->where('id', $subscription->id)->delete();
            } else {
                Log::warning('A push service refused a message.', ['did' => $did, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
            }
        }

        return $sent;
    }

    /**
     * An endpoint the server may post to: https, on a push service in
     * atp-appview.push.hosts.
     *
     * @throws InvalidArgumentException
     */
    public static function checkEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? null) !== 'https' || $host === '') {
            throw new InvalidArgumentException('A push endpoint must be an https URL.');
        }

        $hosts = (array) config('atp-appview.push.hosts', []);

        foreach ($hosts as $allowed) {
            $pattern = '/^'.str_replace('\*', '[a-z0-9.-]+', preg_quote(strtolower((string) $allowed), '/')).'$/';

            if (preg_match($pattern, $host)) {
                return;
            }
        }

        if ($hosts !== []) {
            throw new InvalidArgumentException("{$host} is not a push service this app allows, in atp-appview.push.hosts.");
        }
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
