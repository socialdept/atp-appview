<?php

namespace SocialDept\AtpAppview\Ingest\Contracts;

use SocialDept\AtpAppview\Ingest\Batch;

/**
 * Receives each batch of ingested events.
 *
 * Write the batch in bulk, not one query per event, and apply it idempotently:
 * Tap delivers at least once. Throwing makes the batch retry, and after the
 * last retry it is redelivered by Tap.
 */
interface BatchHandler
{
    public function handle(Batch $batch): void;
}
