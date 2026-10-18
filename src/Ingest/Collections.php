<?php

namespace SocialDept\AtpAppview\Ingest;

/**
 * The collections the index ingests, in two kinds.
 *
 * Followed collections (ingest.collections) decide which repos are followed:
 * appview:discover finds every repo holding one, and adding one backfills
 * those repos. Companion collections (ingest.companions) are indexed only for
 * repos already followed, such as profiles, which nearly every account has:
 * they are never discovered, and adding one backfills the repos the index
 * already knows. Tap's collection filters are both.
 */
class Collections
{
    /**
     * @return array<int, string>
     */
    public static function followed(): array
    {
        return self::read('atp-appview.ingest.collections');
    }

    /**
     * @return array<int, string>
     */
    public static function companions(): array
    {
        return array_values(array_diff(self::read('atp-appview.ingest.companions'), self::followed()));
    }

    /**
     * Every collection the index ingests, followed first.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [...self::followed(), ...self::companions()];
    }

    public static function isCompanion(string $collection): bool
    {
        return in_array($collection, self::companions(), true);
    }

    public static function ingests(string $collection): bool
    {
        return in_array($collection, self::all(), true);
    }

    /**
     * @return array<int, string>
     */
    protected static function read(string $key): array
    {
        return array_values(array_unique(array_filter((array) config($key, []), fn ($nsid) => is_string($nsid) && $nsid !== '')));
    }
}
