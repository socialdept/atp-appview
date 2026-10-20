<?php

/*
|--------------------------------------------------------------------------
| Push
|--------------------------------------------------------------------------
|
| Web Push to the browsers people turn it on in, for the push feature,
| Features::push(). Messages are encrypted and signed with PHP's own
| OpenSSL, so nothing more needs installing.
|
| Make the VAPID keys once with php artisan appview:push:keys and put them
| in .env. Without them nothing is sent. The subject is how a push service
| reaches you about your messages: a mailto: or https: URL.
|
*/

return [
    'public_key' => env('ATP_APPVIEW_PUSH_PUBLIC_KEY'),

    'private_key' => env('ATP_APPVIEW_PUSH_PRIVATE_KEY'),

    'subject' => env('ATP_APPVIEW_PUSH_SUBJECT'),

    // How long a push service keeps a message for a browser that is
    // offline, in seconds.
    'ttl' => 86400,

    // very-low, low, normal or high. Phones may hold back all but high on
    // battery.
    'urgency' => 'normal',

    // The push services a subscription may point at. A browser hands over
    // its endpoint, so without this list anyone could have the server post
    // to any URL. * matches one or more labels. Empty allows any https host.
    'hosts' => [
        'fcm.googleapis.com',
        '*.push.services.mozilla.com',
        '*.notify.windows.com',
        'web.push.apple.com',
        '*.push.apple.com',
    ],

    // Turns a notification into what is pushed, a class implementing
    // Push\Contracts\PushPayload. The default sends its reason, subject,
    // actor and time, for the app's service worker to show.
    'payload' => \SocialDept\AtpAppview\Push\NotificationPayload::class,

    // Built-in XRPC procedures the browser subscribes and unsubscribes
    // through, under NSIDs in the app's own namespace. Null leaves one off.
    //
    //     'subscribe' => 'com.example.push.subscribe',
    //     'unsubscribe' => 'com.example.push.unsubscribe',
    'xrpc' => [
        'subscribe' => null,
        'unsubscribe' => null,
    ],
];
