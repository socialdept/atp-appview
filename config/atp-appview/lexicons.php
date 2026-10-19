<?php

/*
|--------------------------------------------------------------------------
| Followed Lexicons
|--------------------------------------------------------------------------
|
| Published lexicons this AppView follows, usually other people's records
| it indexes. appview:lexicons:sync resolves each through its _lexicon DNS
| record, keeps it in atp_lexicons and writes the version in use to
| `path`, which atp-schema reads after the app's own lexicons. A new
| version that would break the one in use is held back until
| appview:lexicons:accept puts it in use. Schedule the sync hourly.
|
*/

return [
    'follow' => [],

    'path' => storage_path('app/atp-appview/lexicons'),

    // Ask Tap to follow each author's repo, so a new version arrives as
    // an event. Tap also needs com.atproto.lexicon.schema in its filters.
    'track_with_tap' => env('ATP_APPVIEW_TRACK_LEXICONS', true),
];
