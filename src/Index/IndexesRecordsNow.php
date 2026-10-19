<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Contracts\Container\Container;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Pipeline;
use SocialDept\AtpAppview\Models\Record;

/**
 * Indexes one record straight away, ahead of Tap delivering it, through the
 * same pipeline Tap's events pass through: the blocklist, repairs, hooks and
 * every handler. Tap's delivery later changes nothing in the index, since a
 * record is only overwritten by an equal or newer revision.
 */
class IndexesRecordsNow
{
    public function __construct(
        protected Blocklist $blocklist,
        protected Container $container,
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
     * @return bool Whether the index now holds this version, false when a rule or hook kept it out
     */
    public function index(string $did, string $collection, string $rkey, RecordAction $action, ?string $cid, string $rev, ?array $record): bool
    {
        $event = $this->event($did, $collection, $rkey, $action, $cid, $rev, $record);
        Pipeline::fromConfig($this->container)->run(new Batch([$event]));

        return Record::withTrashed()->withHidden()->whereKey($event->uri())->where('rev', $rev)->where('cid', $cid)->exists();
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    protected function event(string $did, string $collection, string $rkey, RecordAction $action, ?string $cid, string $rev, ?array $record): RecordEvent
    {
        return new RecordEvent(0, $did, $collection, $rkey, $action, $cid, $rev, $record, true);
    }
}
