<?php

namespace SocialDept\AtpAppview\Backfill;

use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;

/**
 * Marks repos delivered as Tap sends their backfilled records for a running
 * backfill. Live records say nothing about a backfill, so only those Tap
 * marks live: false count.
 */
class BackfillTracker implements BatchHandler
{
    public function __construct(protected Backfills $backfills)
    {
    }

    public function handle(Batch $batch): void
    {
        $running = $this->backfills->running();

        if ($running === []) {
            return;
        }

        $counts = [];

        foreach ($batch->records() as $event) {
            if ($event->live || ! isset($running[$event->collection])) {
                continue;
            }

            $counts[$event->collection][$event->did] = ($counts[$event->collection][$event->did] ?? 0) + 1;
        }

        foreach ($counts as $collection => $byRepo) {
            $this->backfills->arrived($running[$collection], $byRepo);
        }
    }
}
