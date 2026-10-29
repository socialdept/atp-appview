<?php

/*
|--------------------------------------------------------------------------
| Spaces
|--------------------------------------------------------------------------
|
| A copy of the spaces the app follows, for Features::spaces(). A space is
| permissioned data: its records live in a repo per member on each
| member's own PDS, nothing relays them, and reading them takes a space
| credential. The app says which spaces to follow with
| SpaceSync::follow(), and the AppView keeps their records in
| atp_space_records, apart from the public index. Space types themselves
| are configured in atp-spaces.
|
| A viewer reads a space's records through SpaceRecordQuery while they
| hold a grant: the space's authority issued them a credential through
| the AppView, with SpaceGrants::enter() in backend mode or the enter
| procedure below in frontend mode, and it has not expired.
|
*/

return [
    // Ask each followed space's authority to say when a member's repo
    // advances or the space is deleted, through the routes atp-spaces
    // serves, so syncs follow writes instead of waiting for the schedule.
    // The service is the identifier the authority signs its calls for, a
    // DID and a DID document fragment, ATP_SERVICE_AUTH_AUDIENCE when null.
    // A registration lapses, so it is renewed by appview:spaces:sync once
    // it has less than renew_before seconds left.
    'notify' => [
        'enabled' => true,
        'service' => null,
        'renew_before' => 21600,
    ],

    // Entries per page when listing a space's repos and a repo's operations.
    'page_size' => 100,

    // The procedure a browser enters a space through, in frontend mode,
    // under your own namespace. It takes {space, delegationToken}, the
    // token from the viewer's PDS (getDelegationToken), exchanges it for a
    // credential and answers {space, expiresAt}, when the viewer's grant
    // lapses unless they enter again. The token must name the viewer, who
    // comes from a Laravel session or a service auth token, as any XRPC
    // method does. Null serves no procedure.
    //
    //     'enter' => 'com.example.space.enter',
    'xrpc' => [
        'enter' => null,
    ],
];
