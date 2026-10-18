<?php

namespace SocialDept\AtpAppview\Backfill\Events;

use SocialDept\AtpAppview\Models\Backfill;

/**
 * A backfill handed its repos to Tap.
 */
final class BackfillStarted
{
    public function __construct(public readonly Backfill $backfill)
    {
    }
}
