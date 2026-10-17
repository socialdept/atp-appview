<?php

namespace SocialDept\AtpAppview\Ingest\Events;

/**
 * A change to a tracked account's handle or status. Tap folds account status
 * into its identity events.
 */
final class IdentityEvent
{
    public function __construct(
        public readonly int $id,
        public readonly string $did,
        public readonly ?string $handle,
        public readonly bool $active,
        public readonly ?string $status,
    ) {
    }
}
