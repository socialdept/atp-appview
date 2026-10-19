<?php

namespace SocialDept\AtpAppview\Index;

/**
 * A value as the 8-byte number atp_record_values stores, for exact matching:
 * xxh64, read as a signed 64-bit integer so every database's bigint holds it.
 */
class ValueHash
{
    public static function of(string $value): int
    {
        return unpack('J', hash('xxh64', $value, true))[1];
    }
}
