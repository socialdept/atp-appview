<?php

/*
|--------------------------------------------------------------------------
| Dead Letters
|--------------------------------------------------------------------------
|
| Events the handlers could not take. A batch that keeps failing is split
| until the events failing on their own are found, and those are parked
| here so the rest go through. When every event fails on its own, the cause
| is more likely the database or a service, so the batch goes back to Tap,
| and an event is parked only once it has failed in max_deliveries separate
| deliveries. appview:dead-letters retries, dismisses and prunes them.
|
| How long to keep them depends on how many arrive: keep_days null keeps
| them until dismissed, and max caps how many are kept, oldest dropped first.
|
*/

return [
    'keep_days' => env('ATP_APPVIEW_DEAD_LETTERS_KEEP_DAYS', 90),
    'max' => env('ATP_APPVIEW_DEAD_LETTERS_MAX'),
    'max_deliveries' => (int) env('ATP_APPVIEW_DEAD_LETTERS_MAX_DELIVERIES', 3),
];
