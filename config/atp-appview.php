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
        | Frontend is the default and the safer choice: the server stores no
        | tokens. Choose backend only when the app must act for users while
        | they are away.
        |
        | The guard accepts both kinds of credential either way, so switching
        | modes later does not change endpoint code.
        |
        */

        'mode' => env('ATP_APPVIEW_AUTH_MODE', 'frontend'),

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

        /*
        |----------------------------------------------------------------------
        | Frontend Sign-in
        |----------------------------------------------------------------------
        |
        | The server's half of frontend-mode OAuth. The browser holds a
        | non-extractable DPoP key and runs the authorization request, the
        | token exchange and refreshes itself. These routes keep the PKCE
        | verifier between redirect and callback, sign short-lived client
        | assertions so the app stays a confidential client, resolve accounts,
        | and start a session from a service auth token. They never see an
        | OAuth token. Registered in frontend mode only.
        |
        */

        'frontend' => [

            'routes' => env('ATP_APPVIEW_FRONTEND_ROUTES', true),

            'prefix' => '',

            'middleware' => ['web'],

            // The method a service auth token must be bound to before it can
            // start a session, such as "com.example.createSession". Pick an
            // NSID under your own domain: it only ever travels between the
            // user's PDS and this app.
            'session_method' => env('ATP_APPVIEW_SESSION_METHOD'),

            // Where the callback sends the browser to finish signing in, and
            // where a failed sign-in goes back to. Both are route names.
            'complete_route' => 'login.complete',
            'login_route' => 'login',

            'remember' => true,

            // Updated from the verified handle on every sign-in. Null to skip.
            'handle_column' => 'handle',

            // Servers offered as buttons on the login page. Sign-in starts at
            // the server, which asks for the account itself.
            'providers' => [
                ['key' => 'bluesky', 'name' => 'Bluesky', 'server' => 'https://bsky.social'],
            ],
        ],
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
    | Blocklist
    |--------------------------------------------------------------------------
    |
    | Rules that keep records out of the index, by repo or by PDS host, are
    | managed with appview:block and appview:unblock and live in atp_blocks.
    | Host rules need each repo's PDS, resolved from its DID document and
    | kept on atp_identities for pds_ttl seconds.
    |
    */

    'blocklist' => [
        'pds_ttl' => (int) env('ATP_APPVIEW_PDS_TTL', 86400),
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

        // The record fields that say when a record was made, tried in order,
        // for sort_at. '*' applies to collections not listed. Run
        // appview:resort after changing this, to recompute indexed records.
        'sort_fields' => [
            '*' => ['createdAt'],
            // 'site.standard.document' => ['publishedAt', 'createdAt'],
        ],
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

        // Queries served straight from the index, mapped to the record
        // collection they read, in the shapes getRecord and listRecords use.
        // A handler class for the same method takes precedence.
        //
        //     'com.example.listThings' => 'com.example.thing',
        'collections' => [],

        // Backend mode only. Procedures that write one collection to the
        // viewer's repo through the session the server holds for them, then
        // index the record straight away. Action is create, put or delete,
        // with inputs and outputs as in com.atproto.repo.
        //
        //     'com.example.createThing' => ['collection' => 'com.example.thing', 'action' => 'create'],
        'procedures' => [],

        // Frontend mode. A procedure the browser calls through its PDS after
        // writing a record there itself, with {uri}: the record is fetched
        // from the caller's PDS and indexed now rather than when Tap delivers
        // it. Null leaves it off.
        //
        //     'sync_record' => 'com.example.syncRecord',
        'sync_record' => null,

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
