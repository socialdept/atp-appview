<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auth Mode
    |--------------------------------------------------------------------------
    |
    | Where the user's OAuth tokens live.
    |
    | - 'backend':  the server holds the tokens and the browser holds only a
    |               session cookie. Background jobs can act for the user.
    | - 'frontend': the browser holds the tokens and reaches the AppView
    |               through its PDS, which signs a service auth token.
    |
    | Endpoints accept both kinds of credential either way, so switching
    | modes later does not change endpoint code.
    |
    */

    'auth' => [
        'mode' => env('ATP_APPVIEW_AUTH_MODE', 'backend'),
    ],

];
