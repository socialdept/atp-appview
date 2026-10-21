<?php

namespace SocialDept\AtpAppview\Index\Events;

use SocialDept\AtpAppview\Ingest\Events\RecordAction;

/**
 * A record arriving live was created or updated in the index. Dispatched
 * once the batch is committed, after every handler, so listeners see what
 * the index now holds. Backfills and replays do not dispatch it.
 */
final class RecordIndexed
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $did,
        public readonly string $collection,
        public readonly string $rkey,
        public readonly RecordAction $action,
        public readonly array $record,
    ) {
    }
}
