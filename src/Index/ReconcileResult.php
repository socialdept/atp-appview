<?php

namespace SocialDept\AtpAppview\Index;

/**
 * What reconciling one repo found and changed.
 */
final class ReconcileResult
{
    /**
     * @param  array<int, string>  $indexed  Uris indexed or refreshed from the PDS
     * @param  array<int, string>  $tombstoned  Uris tombstoned because the PDS no longer has them
     */
    public function __construct(
        public readonly string $did,
        public readonly ?string $rev = null,
        public readonly int $listed = 0,
        public readonly array $indexed = [],
        public readonly array $tombstoned = [],
        public readonly ?string $skipped = null,
    ) {
    }

    public function changed(): bool
    {
        return $this->indexed !== [] || $this->tombstoned !== [];
    }
}
