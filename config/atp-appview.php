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

];
