<?php

namespace SocialDept\AtpAppview\Ingest\Events;

/**
 * A record created, updated or deleted in a repo Tap tracks.
 */
final class RecordEvent
{
    /**
     * @param  array<string, mixed>|null  $record  The record body, null for a delete
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
    ) {
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
