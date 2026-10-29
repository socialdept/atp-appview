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
];
