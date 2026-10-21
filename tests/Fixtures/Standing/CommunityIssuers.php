<?php

namespace SocialDept\AtpAppview\Tests\Fixtures\Standing;

use SocialDept\AtpAppview\Standing\Contracts\TrustedIssuers;

/**
 * A community's list of verifiers, here kept in a static for the test.
 */
class CommunityIssuers implements TrustedIssuers
{
    /** @var array<int, string> */
    public static array $dids = [];

    public static int $asked = 0;

    public function dids(): array
    {
        self::$asked++;

        return [...self::$dids, 'not-a-did'];
    }
}
