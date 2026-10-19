<?php

namespace SocialDept\AtpAppview\Ingest\Events;

/**
 * A labeler Tack follows: added, its declaration changed, or removed.
 */
final class LabelerEvent
{
    /**
     * @param  array<string, mixed>|null  $declaration  The app.bsky.labeler.service record
     */
    public function __construct(
        public readonly int $id,
        public readonly string $did,
        public readonly ?string $endpoint,
        public readonly ?array $declaration,
        public readonly bool $removed,
    ) {
    }
}
