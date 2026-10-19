<?php

namespace SocialDept\AtpAppview\Ingest;

use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\LabelerEvent;
use SocialDept\AtpAppview\Ingest\Events\LabelEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;

/**
 * Events committed together, in the order Tap delivered them.
 *
 * Tap delivers at least once, so a batch can repeat events an earlier batch
 * already carried. Handlers must apply them idempotently.
 */
final class Batch
{
    /**
     * @param  array<int, RecordEvent|IdentityEvent|LabelEvent|LabelerEvent>  $events
     */
    public function __construct(
        public readonly array $events,
    ) {
    }

    /**
     * @return array<int, RecordEvent>
     */
    public function records(): array
    {
        return array_values(array_filter($this->events, fn ($event) => $event instanceof RecordEvent));
    }

    /**
     * @return array<int, IdentityEvent>
     */
    public function identities(): array
    {
        return array_values(array_filter($this->events, fn ($event) => $event instanceof IdentityEvent));
    }

    /**
     * @return array<int, LabelEvent>
     */
    public function labels(): array
    {
        return array_values(array_filter($this->events, fn ($event) => $event instanceof LabelEvent));
    }

    /**
     * @return array<int, LabelerEvent>
     */
    public function labelers(): array
    {
        return array_values(array_filter($this->events, fn ($event) => $event instanceof LabelerEvent));
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    public function count(): int
    {
        return count($this->events);
    }
}
