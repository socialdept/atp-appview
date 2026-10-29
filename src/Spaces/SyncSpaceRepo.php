<?php

namespace SocialDept\AtpAppview\Spaces;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SocialDept\AtpSpaces\SpaceRef;

/**
 * Syncs one member's repo in a space after a notification says it moved.
 * One waits in the queue per repo at a time, and another can be queued
 * while it runs. Once synced, the space's stored spaceRev moves to the
 * notification's, when it still holds the one the notification follows.
 */
class SyncSpaceRepo implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  string  $space  The space URI
     * @param  string|null  $spaceRev  The notification's spaceRev
     * @param  string|null  $prevSpaceRev  The spaceRev it follows
     */
    public function __construct(
        public readonly string $space,
        public readonly string $did,
        public readonly ?string $spaceRev = null,
        public readonly ?string $prevSpaceRev = null,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->space.'|'.$this->did;
    }

    public function handle(SpaceSync $sync): void
    {
        $space = SpaceRef::parse($this->space);

        if ($sync->syncRepo($space, $this->did) && $this->spaceRev !== null) {
            $sync->advance($space, $this->prevSpaceRev, $this->spaceRev);
        }
    }
}
