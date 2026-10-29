<?php

namespace SocialDept\AtpAppview\Spaces\Events;

/**
 * A record in a followed space was deleted from its member's repo.
 * Dispatched once the sync is committed, with what the copy held before,
 * empty when it never had it. A full resync from the repo's archive does
 * not dispatch it.
 *
 * Never dispatched as RecordRemoved, so nothing listening for public
 * records hears of it.
 */
final class SpaceRecordRemoved
{
    /**
     * @param  string  $uri  The space record URI
     * @param  array<string, mixed>  $record
     */
    public function __construct(
        public readonly string $space,
        public readonly string $uri,
        public readonly string $did,
        public readonly string $collection,
        public readonly string $rkey,
        public readonly array $record = [],
    ) {
    }
}
