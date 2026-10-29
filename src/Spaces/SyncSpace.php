<?php

namespace SocialDept\AtpAppview\Spaces;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * Catches a whole space up from its authority's repo listing, queued so
 * neither a notification nor following a space waits on the network. One
 * waits in the queue per space at a time, and another can be queued while
 * it runs, so a write arriving during a sync is not missed.
 */
class SyncSpace implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  string  $space  The space URI
     */
    public function __construct(
        public readonly string $space,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->space;
    }

    public function handle(SpaceSync $sync): void
    {
        $sync->sync(SpaceRef::parse($this->space));
    }
}
