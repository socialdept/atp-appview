<?php

namespace SocialDept\AtpAppview\Ingest\Contracts;

use SocialDept\AtpAppview\Ingest\Events\RecordEvent;

/**
 * Looks at records before they are indexed and rejects those the app does
 * not want, such as spam or records breaking an app rule. Listed in
 * atp-appview.ingest.hooks and run in order on every batch, after the
 * blocklist.
 *
 * Only creates and updates are offered: a delete always goes through. A
 * hook never changes a record, so the index matches what is in the repo.
 */
interface IngestHook
{
    /**
     * @param  array<int, RecordEvent>  $records  The batch's creates and updates
     * @return array<string, string> The records to reject, as uri => reason
     */
    public function reject(array $records): array;
}
