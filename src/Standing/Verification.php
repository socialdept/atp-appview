<?php

namespace SocialDept\AtpAppview\Standing;

/**
 * Whether an account is verified, as Bluesky's verificationState has it:
 * valid, invalid (verified once, no longer matching) or none, with each
 * verification behind it and why it holds or does not.
 */
final class Verification
{
    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const NONE = 'none';

    /**
     * @param  array<int, array{issuer: string, uri: string|null, createdAt: string|null, valid: bool, reason: string|null}>  $verifications
     * @param  bool  $complete  False when some verification could not be checked, so the result is tried again soon
     */
    public function __construct(
        public readonly string $status,
        public readonly array $verifications = [],
        public readonly string $method = '',
        public readonly bool $complete = true,
    ) {
    }

    public static function none(string $method = ''): self
    {
        return new self(self::NONE, [], $method);
    }

    /**
     * From each verification: valid if any holds, invalid if some were
     * proven and none holds, none otherwise.
     *
     * @param  array<int, array{issuer: string, uri: string|null, createdAt: string|null, valid: bool, reason: string|null}>  $verifications
     */
    public static function from(array $verifications, string $method, bool $complete = true): self
    {
        $status = match (true) {
            array_filter($verifications, fn (array $verification) => $verification['valid']) !== [] => self::VALID,
            $verifications !== [] => self::INVALID,
            default => self::NONE,
        };

        return new self($status, array_values($verifications), $method, $complete);
    }

    public function verified(): bool
    {
        return $this->status === self::VALID;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'verifications' => $this->verifications,
            'method' => $this->method,
            'complete' => $this->complete,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['status'] ?? self::NONE),
            (array) ($data['verifications'] ?? []),
            (string) ($data['method'] ?? ''),
            (bool) ($data['complete'] ?? true),
        );
    }
}
