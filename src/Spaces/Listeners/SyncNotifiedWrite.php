<?php

namespace SocialDept\AtpAppview\Spaces\Listeners;

use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpAppview\Spaces\SyncSpace;
use SocialDept\AtpAppview\Spaces\SyncSpaceRepo;
use SocialDept\AtpSpaces\Events\SpaceWriteNotified;

/**
 * Queues a sync when a followed space's authority says a member's repo
 * moved: of that repo when the notification follows the last spaceRev
 * stored, else of the whole space, since notifications were missed in
 * between. Notifications for spaces the app does not follow are ignored.
 */
class SyncNotifiedWrite
{
    public function __construct(
        protected SpaceSync $sync,
    ) {
    }

    public function handle(SpaceWriteNotified $event): void
    {
        $row = $this->sync->state($event->space);

        if ($row === null || $row->deleted_at !== null) {
            return;
        }

        if ($event->follows($row->space_rev)) {
            SyncSpaceRepo::dispatch((string) $event->space, $event->repo, $event->spaceRev, $event->prevSpaceRev);

            return;
        }

        SyncSpace::dispatch((string) $event->space);
    }
}
