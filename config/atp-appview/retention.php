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
];
