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
];
