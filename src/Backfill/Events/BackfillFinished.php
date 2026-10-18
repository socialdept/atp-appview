<?php

namespace SocialDept\AtpAppview\Backfill\Events;

use SocialDept\AtpAppview\Models\Backfill;

/**
 * A backfill ended, finished or failed. Check $backfill->status.
 */
final class BackfillFinished
{
    public function __construct(public readonly Backfill $backfill)
    {
    }
}
