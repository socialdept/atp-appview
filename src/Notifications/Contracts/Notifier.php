<?php

namespace SocialDept\AtpAppview\Notifications\Contracts;

use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Notifications\Notify;

/**
 * Decides who a record notifies when no field of it says, such as everyone
 * in a thread or everyone sharing a status. Listed in
 * atp-appview.notifications.notifiers.
 *
 * Offered records as they arrive live, after they are indexed, so a query
 * sees them. A backfill or a replay keeps what a record gave before, and
 * deleting the record removes its notifications. Records already indexed
 * are offered only when appview:notifications:build --notifier asks.
 */
interface Notifier
{
    /**
     * @return array<int, string> The collections whose records it reads
     */
    public function collections(): array;

    /**
     * @param  array<int, Record>  $records  Indexed and not deleted, live or replayed by appview:notifications:build
     * @return iterable<Notify>
     */
    public function notify(array $records): iterable;
}
