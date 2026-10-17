<?php

namespace SocialDept\AtpAppview\Blocklist;

use SocialDept\AtpAppview\Models\Block;

/**
 * What adding or removing a rule did, beyond the rule itself.
 */
final class BlockOutcome
{
    /**
     * @param  int  $purged  Indexed records deleted
     * @param  array<int, string>  $removedFromTap  Repos Tap stopped following
     * @param  array<int, string>  $resyncedInTap  Repos Tap was asked to backfill again
     * @param  string|null  $tapError  Why updating Tap failed, if it did. The rule still applies at ingest.
     */
    public function __construct(
        public readonly Block $block,
        public readonly int $purged = 0,
        public readonly array $removedFromTap = [],
        public readonly array $resyncedInTap = [],
        public readonly ?string $tapError = null,
    ) {
    }
}
