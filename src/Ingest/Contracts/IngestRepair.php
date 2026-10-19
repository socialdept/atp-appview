<?php

namespace SocialDept\AtpAppview\Ingest\Contracts;

use SocialDept\AtpAppview\Ingest\Events\RecordEvent;

/**
 * Rewrites a record the app knows how to fix before it is indexed, such as a
 * date in a format the lexicon does not allow. Listed in
 * atp-appview.ingest.repairs and run in order on every create and update,
 * after the blocklist and before the ingest hooks, so hooks see the repaired
 * record.
 *
 * A repaired record no longer matches its CID. The index keeps the record as
 * it is in the repo beside it, with the repairs that changed it, in
 * atp_records.repair. The author's next version replaces both.
 */
interface IngestRepair
{
    /**
     * @return array<string, mixed>|null The repaired record, or null to leave it as it is
     */
    public function repair(RecordEvent $record): ?array;
}
