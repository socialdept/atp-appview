<?php

/*
|--------------------------------------------------------------------------
| Realtime
|--------------------------------------------------------------------------
|
| Pings over Laravel broadcasting when something changes, for
| Features::realtime(). A ping says what changed (the record's URI, its
| collection and the action), never what it holds: the browser fetches
| through the app's own methods, which apply blocks, mutes and moderation
| for that viewer. Set up broadcasting first (Reverb or Pusher).
|
*/

return [
    // Collections whose new, changed and deleted records ping
    // appview.collection.{nsid}, for "3 new posts".
    'collections' => [],

    // References that ping the record they point at, on
    // appview.subject.{hash}, so counts and replies stay current. Each is a
    // collection and the paths in it that hold the AT-URI.
    //
    //     'com.example.like' => ['subject.uri'],
    //     'com.example.post' => ['reply.parent.uri'],
    'subjects' => [],

    // Each account hears on its private appview.account.{hash} when a
    // notification arrives, with the notifications feature.
    'notifications' => true,

    // Pings on one channel within this many seconds become one, with a
    // count, so a busy record does not send a message for each like.
    'window' => 1,

    // The procedure the browser asks to join a private channel through,
    // under your own namespace. It takes a Laravel session or a service auth
    // token, as any XRPC method does. Null leaves private channels off.
    //
    //     'authorize' => 'com.example.realtime.authorize',
    'xrpc' => [
        'authorize' => null,
    ],
];
