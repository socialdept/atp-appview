<?php

return [

    'auth' => [

        /*
        |----------------------------------------------------------------------
        | Auth Mode
        |----------------------------------------------------------------------
        |
        | Where the user's OAuth tokens live.
        |
        | - 'backend':  the server holds the tokens and the browser holds only
        |               a session cookie. Background jobs can act for the user.
        | - 'frontend': the browser holds the tokens and reaches the AppView
        |               through its PDS, which signs a service auth token.
        |
        | The guard accepts both kinds of credential either way, so switching
        | modes later does not change endpoint code.
        |
        */

        'mode' => env('ATP_APPVIEW_AUTH_MODE', 'backend'),

        // The guard the atp.viewer middleware resolves, configured in
        // config/auth.php with the "atp" driver.
        'guard' => env('ATP_APPVIEW_GUARD', 'atp'),

        // The session guard the atp guard wraps. A guard's own session_guard
        // option takes precedence.
        'session_guard' => env('ATP_APPVIEW_SESSION_GUARD', 'web'),

        // What happens when a verified DID has no local user: 'create' makes
        // one keyed by did_column, 'none' leaves the viewer without a user.
        'provision' => env('ATP_APPVIEW_PROVISION', 'create'),

        'did_column' => env('ATP_APPVIEW_DID_COLUMN', 'did'),

        // Services allowed to call with their own did#serviceId as issuer.
        // A service is never provisioned as a user.
        'trusted_services' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingest
    |--------------------------------------------------------------------------
    |
    | appview:consume reads Tap's acknowledged websocket channel in batches
    | and hands each batch to the handlers below. Tab, the Tap fork, speaks the
    | same protocol. Never point this at Tap's webhook mode: one request per
    | record floods the application during a backfill.
    |
    */

    'ingest' => [
        'url' => env('ATP_APPVIEW_TAP_URL', 'ws://localhost:2480/channel'),

        // Tap's admin API, for adding and removing repos and reading progress.
        'admin_url' => env('ATP_APPVIEW_TAP_ADMIN_URL', 'http://localhost:2480'),

        // TAP_ADMIN_PASSWORD, when Tap is configured with one.
        'password' => env('ATP_APPVIEW_TAP_PASSWORD'),

        // A batch is handled when it reaches batch_size events or flush_ms
        // milliseconds pass, whichever comes first.
        'batch_size' => (int) env('ATP_APPVIEW_INGEST_BATCH_SIZE', 200),
        'flush_ms' => (int) env('ATP_APPVIEW_INGEST_FLUSH_MS', 500),

        // Attempts per batch before giving up and letting Tap redeliver it.
        'max_attempts' => (int) env('ATP_APPVIEW_INGEST_MAX_ATTEMPTS', 3),

        // Collections appview:discover finds repos for. Tap follows new repos
        // live only for its own TAP_SIGNAL_COLLECTION, so schedule discovery
        // to pick up repos that start using the others.
        'collections' => [],

        // Where appview:discover lists repos by collection: a relay, or
        // Light Rail at https://lightrail.microcosm.blue.
        'discovery_url' => env('ATP_APPVIEW_DISCOVERY_URL', 'https://relay1.us-east.bsky.network'),

        // Classes implementing BatchHandler, run in order for every batch.
        // RecordIndexer writes the records index.
        'handlers' => [
            \SocialDept\AtpAppview\Index\RecordIndexer::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    |
    | Where atp_records and atp_identities live. Null uses the default
    | connection. Postgres, MySQL, MariaDB and SQLite are supported.
    |
    */

    'index' => [
        'connection' => env('ATP_APPVIEW_INDEX_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | XRPC
    |--------------------------------------------------------------------------
    |
    | Every class with an #[Xrpc] attribute becomes a route at
    | /{prefix}/{nsid}: GET for a query, POST for a procedure, as its lexicon
    | says. Lexicons load through atp-schema's sources, resources/lexicons by
    | default. Parameters and input are validated against them.
    |
    */

    'xrpc' => [
        'prefix' => env('ATP_APPVIEW_XRPC_PREFIX', 'xrpc'),

        // Applied to every XRPC route, before the atp.viewer guard. Add 'web'
        // for session viewers in backend mode, or throttling.
        'middleware' => [],

        // Handler classes registered explicitly.
        'handlers' => [],

        // Classes carrying #[Xrpc] under this path are registered too.
        'discover' => [
            'path' => app_path('Http/Xrpc'),
            'namespace' => 'App\\Http\\Xrpc',
        ],

        // Answer unknown methods with MethodNotImplemented, and known ones
        // called with the wrong HTTP method with InvalidRequest.
        'fallback' => true,
    ],

];
