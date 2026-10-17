<?php

namespace SocialDept\AtpAppview\Index;

use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;

/**
 * Indexes one record straight away, ahead of Tap delivering it, through the
 * same blocklist and indexer Tap's events pass through. Tap's delivery later
 * changes nothing, since a record is only overwritten by an equal or newer
 * revision.
 */
class IndexesRecordsNow
{
    public function __construct(
        protected Blocklist $blocklist,
        protected RecordIndexer $indexer,
    ) {
    }

    /**
     * Whether a rule keeps this repo's records in this collection out.
     */
    public function blocked(string $did, string $collection): bool
    {
        return $this->blocklist->filter(new Batch([$this->event($did, $collection, 'x', RecordAction::Create, null, '', null)]))->isEmpty();
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    public function index(string $did, string $collection, string $rkey, RecordAction $action, ?string $cid, string $rev, ?array $record): void
    {
        $batch = $this->blocklist->filter(new Batch([$this->event($did, $collection, $rkey, $action, $cid, $rev, $record)]));

        if (! $batch->isEmpty()) {
            $this->indexer->handle($batch);
        }
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    protected function event(string $did, string $collection, string $rkey, RecordAction $action, ?string $cid, string $rev, ?array $record): RecordEvent
    {
        return new RecordEvent(0, $did, $collection, $rkey, $action, $cid, $rev, $record, true);
    }
}
