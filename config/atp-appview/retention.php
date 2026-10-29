<?php

/*
|--------------------------------------------------------------------------
| Retention
|--------------------------------------------------------------------------
|
| How much the index keeps, and how it stores it. Everything here is off
| until set, so an app that sets nothing keeps every record as it is.
|
*/

return [
    // Compression for stored record bodies, on Postgres 14 or later built
    // with LZ4: 'lz4', or null for the server's default. appview:compress
    // applies it to the columns, and --recompress rewrites the rows stored
    // before. MySQL, MariaDB and SQLite have no column equivalent, so it
    // does nothing there.
    'compression' => env('ATP_APPVIEW_RECORD_COMPRESSION'),

    // Days a tombstone is kept before appview:prune deletes it, by
    // collection, '*' for every other one. Null keeps them. A tombstone is
    // what stops an older copy of a deleted record from bringing it back,
    // so keep the window well past how far back anything can replay.
    'tombstones' => [
        '*' => env('ATP_APPVIEW_TOMBSTONE_DAYS'),
    ],

    // Days a record is kept, by sort_at, for collections that only matter
    // while recent, such as read receipts. appview:prune deletes older ones
    // outright, and ingest skips records already older. There is no '*':
    // only collections named here are ever pruned by age.
    //
    //     'com.example.read' => 30,
    'max_age' => [],

    // Days before a record's body is cleared to save space, by sort_at, by
    // collection, '*' for every other one. Null keeps bodies. The row stays,
    // with its field values, counts and references, and appview:prune clears
    // only the body, which is fetched again when something reads it.
    'cold' => [
        '*' => env('ATP_APPVIEW_COLD_AFTER_DAYS'),
    ],

    // Where cold bodies are fetched from: slingshot, at
    // atp-support.microcosm.slingshot.url, or pds, each record's own. Bodies
    // are cached by CID for cache_ttl seconds.
    'cold_fetch' => [
        'from' => env('ATP_APPVIEW_COLD_FETCH_FROM', 'slingshot'),
        'timeout' => (int) env('ATP_APPVIEW_COLD_FETCH_TIMEOUT', 5),
        'cache_ttl' => (int) env('ATP_APPVIEW_COLD_FETCH_CACHE_TTL', 86400),
    ],
];
