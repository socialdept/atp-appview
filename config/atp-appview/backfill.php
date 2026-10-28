<?php

/*
|--------------------------------------------------------------------------
| Backfill
|--------------------------------------------------------------------------
|
| appview:backfill, and appview:backfill:work on a schedule, backfill
| collections the index starts ingesting. With Jetstream as the source,
| queued jobs read each repo's records from its PDS, so run a queue
| worker. Otherwise Tap backfills them: a Tap with resync routes, such as
| Tab, keeps what it already sent, and on any other Tap, repos are removed
| and added again.
|
*/

return [
    // Repos handed to Tap per request.
    'chunk' => (int) env('ATP_APPVIEW_BACKFILL_CHUNK', 500),

    // Repos read from their PDSes per queued job, with Jetstream. More
    // workers read more repos at once.
    'reconcile_chunk' => (int) env('ATP_APPVIEW_BACKFILL_RECONCILE_CHUNK', 25),

    // How long Tap must go without delivering before a backfill whose
    // repos have all delivered is finished. A repo counts as delivered at
    // its first record, so this waits out the rest.
    'settle_seconds' => (int) env('ATP_APPVIEW_BACKFILL_SETTLE_SECONDS', 60),

    // How long Tap may go without delivering a repo before a backfill is
    // ended anyway. The repos left had nothing in the collection, or Tap
    // could not fetch them.
    'quiet_minutes' => (int) env('ATP_APPVIEW_BACKFILL_QUIET_MINUTES', 10),
];
