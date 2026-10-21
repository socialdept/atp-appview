<?php

namespace SocialDept\AtpAppview\Index\Events;

/**
 * A record arriving live was deleted from its repo. Dispatched once the
 * batch is committed, with what it held before, empty when the index never
 * had it. Backfills and replays do not dispatch it.
 */
final class RecordRemoved
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $did,
        public readonly string $collection,
        public readonly string $rkey,
        public readonly array $record = [],
    ) {
    }
}
