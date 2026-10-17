<?php

namespace SocialDept\AtpAppview\Index;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * When a record was made, for ordering lists.
 *
 * The first usable date among the collection's time fields (createdAt unless
 * configured otherwise, such as publishedAt for posts), capped at the time it
 * was indexed, the rule Bluesky's AppView uses: a client cannot pin a record
 * to the top by claiming a future date. Without a usable field, the timestamp
 * a TID record key encodes is used, and failing that the index time.
 *
 * Dates before 1970 are treated as missing. They are almost always
 * placeholders, and MySQL's TIMESTAMP cannot hold them.
 */
final class SortAt
{
    protected const TID = '/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/';

    protected const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';

    protected const EARLIEST = '1970-01-02';

    /**
     * @param  array<string, mixed>|null  $record
     * @param  array<int, string>  $fields  Record fields to try, in order
     */
    public static function for(?array $record, string $rkey, DateTimeInterface $indexedAt, array $fields = ['createdAt']): string
    {
        $indexedAt = DateTimeImmutable::createFromInterface($indexedAt)->setTimezone(new DateTimeZone('UTC'));
        $made = self::fromFields($record, $fields) ?? self::tidTime($rkey);
        $at = $made !== null && $made < $indexedAt ? $made : $indexedAt;

        return $at->format('Y-m-d H:i:s.u');
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @param  array<int, string>  $fields
     */
    protected static function fromFields(?array $record, array $fields): ?DateTimeImmutable
    {
        foreach ($fields as $field) {
            $date = self::parse($record[$field] ?? null);

            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    /**
     * A datetime as records actually write it: ISO 8601, or with a space for
     * the T, or with a time part too broken to read, in which case the date
     * alone is used.
     */
    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(.*)$/', trim($value), $match)) {
            return null;
        }

        // PHP rolls 2024-02-30 over to March. A date that does not exist is missing.
        if (! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $date = "{$match[1]}-{$match[2]}-{$match[3]}";
        $rest = $match[4];
        $candidates = $rest !== '' && ($rest[0] === 'T' || $rest[0] === ' ') ? ["{$date}T".substr($rest, 1), $date] : [$date];

        foreach ($candidates as $candidate) {
            try {
                $parsed = (new DateTimeImmutable($candidate, $utc))->setTimezone($utc);
            } catch (Throwable) {
                continue;
            }

            return self::plausible($parsed) ? $parsed : null;
        }

        return null;
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

        return $time !== false && self::plausible($time) ? $time : null;
    }

    protected static function plausible(DateTimeImmutable $date): bool
    {
        return $date >= new DateTimeImmutable(self::EARLIEST, new DateTimeZone('UTC'));
    }
}
