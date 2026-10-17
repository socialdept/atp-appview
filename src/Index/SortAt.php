<?php

namespace SocialDept\AtpAppview\Index;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * When a record was made, for ordering lists.
 *
 * The record's own createdAt, capped at the time it was indexed, the rule
 * Bluesky's AppView uses: a client cannot pin a record to the top by claiming
 * a future date. Without a usable createdAt, the timestamp a TID record key
 * encodes is used, and failing that the index time.
 */
final class SortAt
{
    protected const TID = '/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/';

    protected const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';

    /**
     * TIDs decoding to before this are not timestamps, just keys that happen
     * to look like one.
     */
    protected const EARLIEST = '2022-01-01';

    /**
     * @param  array<string, mixed>|null  $record
     */
    public static function for(?array $record, string $rkey, DateTimeInterface $indexedAt): string
    {
        $indexedAt = DateTimeImmutable::createFromInterface($indexedAt)->setTimezone(new DateTimeZone('UTC'));
        $made = self::createdAt($record) ?? self::tidTime($rkey);

        $at = $made !== null && $made < $indexedAt ? $made : $indexedAt;

        return $at->format('Y-m-d H:i:s.u');
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    protected static function createdAt(?array $record): ?DateTimeImmutable
    {
        $value = $record['createdAt'] ?? null;

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $value)) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    protected static function tidTime(string $rkey): ?DateTimeImmutable
    {
        if (! preg_match(self::TID, $rkey)) {
            return null;
        }

        $value = 0;

        foreach (str_split($rkey) as $char) {
            $value = ($value << 5) | strpos(self::ALPHABET, $char);
        }

        // The top 54 bits are microseconds since the epoch, the low 10 a clock id.
        $micros = $value >> 10;
        $time = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($micros, 1_000_000), $micros % 1_000_000), new DateTimeZone('UTC'));

        return $time !== false && $time >= new DateTimeImmutable(self::EARLIEST, new DateTimeZone('UTC')) ? $time : null;
    }
}
