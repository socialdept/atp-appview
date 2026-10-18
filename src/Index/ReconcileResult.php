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
        // An account status the PDS reported (deleted, deactivated,
        // takendown, suspended), or reactivated when it serves the repo again.
        public readonly ?string $account = null,
    ) {
    }

    public function changed(): bool
    {
        return $this->indexed !== [] || $this->tombstoned !== [];
    }

    /**
     * One line saying what happened, for a notification or a log.
     */
    public function summary(): string
    {
        $account = $this->account !== null ? ", account {$this->account}" : '';

        return match (true) {
            $this->skipped !== null => "Skipped: {$this->skipped}",
            $this->account !== null && $this->rev === null => "Account {$this->account}",
            $this->changed() => count($this->indexed).' indexed, '.count($this->tombstoned).' tombstoned'.$account,
            $this->account !== null => "In step with its PDS{$account}",
            default => 'In step with its PDS',
        };
    }
}
