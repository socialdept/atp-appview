<?php

namespace SocialDept\AtpAppview\Push;

use InvalidArgumentException;
use RuntimeException;
use SocialDept\AtpClient\Crypto\P256;

/**
 * The Web Push protocol, with PHP's own OpenSSL and nothing else: payloads
 * encrypted for a browser's subscription (RFC 8291, aes128gcm from RFC
 * 8188) and requests signed for the push service (VAPID, RFC 8292).
 */
final class WebPush
{
    /** One record, the size every push service accepts. */
    public const RECORD_SIZE = 4096;

    /** What fits in the 4,096 bytes every push service accepts, after the header, the delimiter and the tag. */
    public const MAX_PAYLOAD = self::RECORD_SIZE - 16 - 1 - 86;

    /**
     * Encrypt a payload for a subscription. The sender's key pair and the
     * salt are fresh for every message, and given here only by tests.
     *
     * @param  string  $userPublic  The subscription's p256dh key, raw
     * @param  string  $authSecret  The subscription's auth secret, raw
     * @param  array{private: string, public: string}|null  $sender  Raw private scalar and public point
     */
    public static function encrypt(string $payload, string $userPublic, string $authSecret, ?array $sender = null, ?string $salt = null): string
    {
        if (strlen($payload) > self::MAX_PAYLOAD) {
            throw new InvalidArgumentException('A push payload holds at most '.self::MAX_PAYLOAD.' bytes, and this one is '.strlen($payload).'.');
        }

        if (strlen($userPublic) !== 65 || $userPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new InvalidArgumentException('A subscription needs a 65-byte p256dh key and a 16-byte auth secret.');
        }

        [$senderKey, $senderPublic] = $sender === null
            ? self::keyPair()
            : [openssl_pkey_get_private(self::privatePem($sender['private'], $sender['public'])), $sender['public']];
        $salt ??= random_bytes(16);

        $secret = openssl_pkey_derive(openssl_pkey_get_public(self::publicPem($userPublic)), $senderKey, 32);

        if ($secret === false) {
            throw new RuntimeException('Could not agree a key with the subscription.');
        }

        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0".$userPublic.$senderPublic, $authSecret);
        $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // One record, so the padding delimiter is the last one's, 0x02.
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return $salt.pack('N', self::RECORD_SIZE).chr(strlen($senderPublic)).$senderPublic.$cipher.$tag;
    }

    /**
     * The Authorization header for a push service: a VAPID token for the
     * endpoint's origin, signed with the application server's key.
     *
     * @param  string  $privatePem  The application server's private key
     * @param  string  $publicKey  Its public point, raw
     */
    public static function vapid(string $endpoint, string $privatePem, string $publicKey, string $subject, int $expiresIn = 43200): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        $input = self::base64url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.self::base64url((string) json_encode(['aud' => $audience, 'exp' => time() + $expiresIn, 'sub' => $subject], JSON_UNESCAPED_SLASHES));

        if (! openssl_sign($input, $der, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the VAPID token.');
        }

        return 'vapid t='.$input.'.'.self::base64url(self::rawSignature($der)).', k='.self::base64url($publicKey);
    }

    public static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function fromBase64url(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }

    /**
     * A fresh P-256 key pair for one message: the key, and its public point.
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}
     */
    public static function keyPair(): array
    {
        // Made by atp-client rather than openssl_pkey_new(), which some
        // OpenSSL builds refuse for EC keys without a configured key size.
        $pair = P256::create();
        $key = openssl_pkey_get_private($pair->toPEM());

        if ($key === false) {
            throw new RuntimeException('Could not make a key pair for the message.');
        }

        return [$key, self::publicPoint($pair)];
    }

    /**
     * A P-256 key's public point, uncompressed and raw, as push services and
     * browsers use it.
     */
    public static function publicPoint(P256 $key): string
    {
        $jwk = $key->toJWK()->asPublic()->toArray();

        return "\x04".self::fromBase64url($jwk['x']).self::fromBase64url($jwk['y']);
    }

    /**
     * A raw P-256 public point as a PEM public key.
     */
    protected static function publicPem(string $point): string
    {
        return self::pem('PUBLIC KEY', hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point);
    }

    /**
     * A raw P-256 private scalar and its public point as a PEM private key.
     */
    protected static function privatePem(string $scalar, string $point): string
    {
        return self::pem('EC PRIVATE KEY', hex2bin('30770201010420').$scalar.hex2bin('a00a06082a8648ce3d030107a144034200').$point);
    }

    protected static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
    }

    /**
     * OpenSSL's DER-encoded ECDSA signature as the r and s a JWT wants,
     * 32 bytes each.
     */
    protected static function rawSignature(string $der): string
    {
        $offset = 2;
        $parts = [];

        foreach ([0, 1] as $_) {
            $length = ord($der[$offset + 1]);
            $parts[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }
}
