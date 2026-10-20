<?php

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| Which records notify whom. Each collection names the fields that point at
| an account or a record, with the reason a notification gives. A field
| holding a DID notifies that account, one holding an AT-URI notifies the
| account in it. Nobody is notified about their own records.
|
|     'reasons' => [
|         'xyz.statusphere.like' => ['subject.uri' => 'like'],
|         'xyz.statusphere.status' => ['reply.parent.uri' => 'reply'],
|         'xyz.statusphere.graph.follow' => ['subject' => 'follow'],
|     ],
|
| Notifications with the same reason and subject share a group key, so
| "Alice and 4 others liked your status" is one group. Reasons listed in
| `separate` give each notification its own group, as replies usually do.
|
| Subjects and the records that notified are hydrated with the view of their
| collection, from atp-appview.xrpc.views and atp-appview.views, as the
| recipient sees it.
|
*/

return [
    'reasons' => [],

    'separate' => [],

    // The newest notifications a group shows, with their actors.
    'latest' => 3,

    // Built-in XRPC methods, each served under the NSID given here, in the
    // app's own namespace. A lexicon is optional. Null leaves one off.
    //
    //     'list' => 'com.example.notification.listNotifications',
    //     'unread_count' => 'com.example.notification.getUnreadCount',
    //     'update_seen' => 'com.example.notification.updateSeen',
    'xrpc' => [
        'list' => null,
        'unread_count' => null,
        'update_seen' => null,
    ],
];
