<?php

/*
|--------------------------------------------------------------------------
| PDS Hosts
|--------------------------------------------------------------------------
|
| The PDSes the operator runs. An account whose PDS is one of these is
| hosted here, so its identity page offers a takedown on the PDS as well
| as a ban from the app. Each host names a driver: admin, the stock PDS
| admin API with its admin password, or a class implementing
| SocialDept\AtpAppview\Pds\Contracts\PdsDriver for a host that speaks
| something else.
|
*/

return [
    'hosts' => array_values(array_filter([
        env('ATP_APPVIEW_PDS_URL') ? [
            'driver' => 'admin',
            'url' => env('ATP_APPVIEW_PDS_URL'),
            'password' => env('ATP_APPVIEW_PDS_ADMIN_PASSWORD'),
        ] : null,
    ])),

    'drivers' => [
        'admin' => \SocialDept\AtpAppview\Pds\Drivers\AdminDriver::class,
    ],
];
