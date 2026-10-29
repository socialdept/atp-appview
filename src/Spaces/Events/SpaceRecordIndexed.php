<?php

namespace SocialDept\AtpAppview\Spaces\Events;

use SocialDept\AtpAppview\Ingest\Events\RecordAction;

/**
 * A record in a followed space was created or updated in the AppView's
 * copy, from a member's operation log. Dispatched once the sync is
 * committed. A full resync from the repo's archive does not dispatch it.
 *
 * Never dispatched as RecordIndexed, so nothing listening for public
 * records, realtime's public channels among them, hears of it.
 */
final class SpaceRecordIndexed
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
        public readonly RecordAction $action,
        public readonly array $record,
    ) {
    }
}
