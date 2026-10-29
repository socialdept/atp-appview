<?php

namespace SocialDept\AtpAppview\Spaces\Listeners;

use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpSpaces\Events\SpaceDeletedNotified;

/**
 * Drops the copy of a followed space when its authority says it was
 * deleted.
 */
class DropDeletedSpace
{
    public function __construct(
        protected SpaceSync $sync,
    ) {
    }

    public function handle(SpaceDeletedNotified $event): void
    {
        $this->sync->deleted($event->space);
    }
}
