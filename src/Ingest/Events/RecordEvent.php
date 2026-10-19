<?php

namespace SocialDept\AtpAppview\Ingest\Events;

/**
 * A record created, updated or deleted in a repo Tap tracks.
 */
final class RecordEvent
{
    /**
     * @param  array<string, mixed>|null  $record  The record body, null for a delete
     * @param  array<int, class-string>  $repairedBy  The repairs that changed the record, in order
     * @param  array<string, mixed>|null  $original  The record as it is in the repo, when a repair changed it
     */
    public function __construct(
        public readonly int $id,
        public readonly string $did,
        public readonly string $collection,
        public readonly string $rkey,
        public readonly RecordAction $action,
        public readonly ?string $cid,
        public readonly string $rev,
        public readonly ?array $record,
        public readonly bool $live,
        public readonly array $repairedBy = [],
        public readonly ?array $original = null,
    ) {
    }

    /**
     * A copy carrying the record as a repair rewrote it. The record as it is
     * in the repo is kept, however many repairs change it.
     *
     * @param  array<string, mixed>  $record
     * @param  class-string  $repair
     */
    public function repaired(array $record, string $repair): self
    {
        return new self(
            $this->id,
            $this->did,
            $this->collection,
            $this->rkey,
            $this->action,
            $this->cid,
            $this->rev,
            $record,
            $this->live,
            [...$this->repairedBy, $repair],
            $this->original ?? $this->record,
        );
    }

    public function uri(): string
    {
        return "at://{$this->did}/{$this->collection}/{$this->rkey}";
    }

    /**
     * Whether the event came from backfilling a repo export rather than the
     * live firehose.
     */
    public function isBackfill(): bool
    {
        return ! $this->live;
    }
}
