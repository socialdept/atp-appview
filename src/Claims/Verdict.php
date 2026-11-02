<?php

namespace SocialDept\AtpAppview\Claims;

/**
 * A checker's answer for one record: the claim holds, it does not, or the
 * check could not tell, such as when the host is down.
 */
final class Verdict
{
    public const VERIFIED = 'verified';

    public const UNVERIFIED = 'unverified';

    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $result,
        public readonly ?string $reason = null,
    ) {
    }

    public static function verified(): self
    {
        return new self(self::VERIFIED);
    }

    public static function unverified(?string $reason = null): self
    {
        return new self(self::UNVERIFIED, $reason);
    }

    public static function unknown(?string $reason = null): self
    {
        return new self(self::UNKNOWN, $reason);
    }
}
