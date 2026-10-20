<?php

use SocialDept\AtpAppview\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | What this AppView turns on beyond the core, as Laravel Fortify lists
    | its features. A feature's helper takes options for its config section:
    | Features::notifications(['latest' => 5]). A third party's feature is
    | listed by its class name. Leaving one out keeps its routes, tables and
    | commands out of the app, as each feature moves behind this list.
    |
    | Call Features::schedule() in routes/console.php to schedule what the
    | enabled features recommend, or schedule their commands yourself.
    |
    */

    'features' => [
        Features::moderation(),
        Features::notifications(),
        Features::graph(),
    ],

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
    | Tap delivers events in batches to the webhook below, and each batch goes
    | to the handlers. This needs batched webhooks, which Tab, a fork of Tap
    | at tangled.org/pds.dad/tab, adds. Stock Tap sends one request per
    | event, which floods the application during a backfill, so with stock
    | Tap, run appview:consume, which reads its websocket channel instead.
    |
    */

    'ingest' => [
        // Tap's websocket channel, for appview:consume.
        'url' => env('ATP_APPVIEW_TAP_URL', 'ws://localhost:2480/channel'),

        // Tap's admin API, for adding and removing repos and reading progress.
        'admin_url' => env('ATP_APPVIEW_TAP_ADMIN_URL', 'http://localhost:2480'),

        // Tap's admin password. Tap signs webhook requests with it, so the
        // webhook refuses everything until it is set.
        'password' => env('ATP_APPVIEW_TAP_PASSWORD'),

        // appview:consume handles a batch when it reaches batch_size events or
        // flush_ms milliseconds pass, whichever comes first. A webhook batch
        // is sized by Tap's TAB_WEBHOOK_BATCH_SIZE instead.
        'batch_size' => (int) env('ATP_APPVIEW_INGEST_BATCH_SIZE', 200),
        'flush_ms' => (int) env('ATP_APPVIEW_INGEST_FLUSH_MS', 500),

        // Attempts per batch before giving up and letting Tap redeliver it.
        'max_attempts' => (int) env('ATP_APPVIEW_INGEST_MAX_ATTEMPTS', 3),

        // Collections the index ingests, comma separated in the environment.
        // A repo holding one is followed: appview:discover finds them, and
        // appview:backfill:work backfills a collection added here once Tap has
        // been restarted with it.
        'collections' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATP_APPVIEW_COLLECTIONS', ''))))),

        // Collections indexed only for repos already followed, such as
        // profiles, which nearly every account has. They are never discovered,
        // and adding one backfills the repos the index already knows. Tap's
        // collection filters are both lists.
        'companions' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATP_APPVIEW_COMPANION_COLLECTIONS', ''))))),

        // Where appview:discover lists repos by collection: a relay, or
        // Light Rail at https://lightrail.microcosm.blue.
        'discovery_url' => env('ATP_APPVIEW_DISCOVERY_URL', 'https://relay1.us-east.bsky.network'),

        // Where Tap delivers events. Point Tap's TAB_WEBHOOK_URL at this path
        // and set TAB_WEBHOOK_BATCH_SIZE. Turn it off when running
        // appview:consume instead.
        'webhook' => [
            'enabled' => (bool) env('ATP_APPVIEW_TAP_WEBHOOK', true),
            'path' => env('ATP_APPVIEW_TAP_WEBHOOK_PATH', 'atp-appview/tap'),
            'middleware' => [],
        ],

        // What a batch passes through before the handlers, in order: classes
        // with handle(Batch $batch, Closure $next), as in a Laravel pipeline.
        'pipes' => [
            \SocialDept\AtpAppview\Ingest\Pipes\DropBlocked::class,
            \SocialDept\AtpAppview\Ingest\Pipes\RunRepairs::class,
            \SocialDept\AtpAppview\Ingest\Pipes\RunHooks::class,
        ],

        // Classes implementing IngestRepair, which rewrite records the app
        // knows how to fix, such as a malformed date, before the hooks see
        // them. Off until listed. A repaired record keeps the original
        // beside it in atp_records.repair.
        'repairs' => [],

        // Classes implementing IngestHook, which reject records the app does
        // not want before they are indexed. They never change a record.
        'hooks' => [],

        // Classes implementing BatchHandler, run in order for every batch.
        // RecordIndexer writes the records index.
        'handlers' => [
            \SocialDept\AtpAppview\Index\RecordIndexer::class,
            \SocialDept\AtpAppview\Lexicons\FollowedLexiconsHandler::class,
            // Marks repos delivered as a running backfill's records arrive.
            \SocialDept\AtpAppview\Backfill\BackfillTracker::class,
            // Enabled features add their own after these, such as
            // moderation's LabelIndexer and notifications' NotificationIndexer.
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

        // Check records against their collection's lexicon before indexing,
        // for collections with a lexicon atp-schema can load. 'off', 'log'
        // (index invalid records and log them) or 'drop' (leave them out).
        // Fields a lexicon does not mention are allowed.
        'validate' => env('ATP_APPVIEW_VALIDATE_RECORDS', 'off'),

        // The record fields that say when a record was made, tried in order,
        // for sort_at. '*' applies to collections not listed. Run
        // appview:resort after changing this, to recompute indexed records.
        'sort_fields' => [
            '*' => ['createdAt'],
            // 'site.standard.document' => ['publishedAt', 'createdAt'],
        ],

        // appview:reconcile compares each repo with its own PDS and fixes
        // what Tap never delivered. Tap's resync sends only records that
        // changed and never a delete, so a record removed while Tap was not
        // following the repo stays indexed until reconciled. Schedule the
        // command every few minutes and it works through the active repos,
        // least recently checked first, in every ingested collection.
        'reconcile' => [
            // Repos per run.
            'batch' => (int) env('ATP_APPVIEW_RECONCILE_BATCH', 25),

            // Hours before a repo is checked again.
            'every_hours' => (int) env('ATP_APPVIEW_RECONCILE_EVERY_HOURS', 24),

            // A collection holding more records than this is left alone and
            // the repo is marked with an error, rather than listed for ever.
            'max_records' => (int) env('ATP_APPVIEW_RECONCILE_MAX_RECORDS', 10000),
        ],

        // An account that is deactivated, taken down or suspended may come
        // back, so its records are hidden rather than deleted. After this many
        // days inactive, appview:purge-inactive tombstones them. If the
        // account returns later, appview:reconcile restores them from its
        // PDS. A deleted account's records are tombstoned straight away.
        'inactive' => [
            'purge_after_days' => (int) env('ATP_APPVIEW_PURGE_INACTIVE_AFTER_DAYS', 180),
        ],

        // Reference fields kept in atp_record_refs beyond those the views'
        // Count and ViewerRef parts already name, by collection, as dot
        // paths. appview:refs builds a field added here for records already
        // indexed.
        //
        //     'com.example.like' => ['subject.uri'],
        'refs' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Identity
    |--------------------------------------------------------------------------
    |
    | A PDS proxying a request with atproto-proxy resolves the AppView's DID
    | to find where to send it. When atp-client.service_auth.audience is a
    | did:web, such as did:web:example.com#atproto_appview, set the endpoint
    | and the DID document is served at /.well-known/did.json with that
    | service in it.
    |
    */

    'service' => [
        // Where this AppView's XRPC routes are reached from the internet, such
        // as https://example.com. Null serves no DID document.
        'endpoint' => env('ATP_APPVIEW_SERVICE_ENDPOINT'),

        // The service type published for the audience's #fragment.
        'type' => 'AtprotoAppView',
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

        // Queries that hydrate one collection's records into a view, with the
        // same paging, repo filter and lookup by uri, answering a page of
        // views under the view's key, such as {things: [...], cursor}.
        //
        //     'com.example.getThings' => \App\Views\ThingView::class,
        'views' => [],

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
