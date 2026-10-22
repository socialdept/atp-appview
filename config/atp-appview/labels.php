<?php

/*
|--------------------------------------------------------------------------
| Labels
|--------------------------------------------------------------------------
|
| Labels come from labelers, through Tack or from each labeler's own
| stream (source). Tack follows them, verifies their signatures and
| delivers the labels about the accounts the index knows, by webhook or
| over its websocket, and appview:labels:sync keeps its subjects in step
| with atp_identities. With streams, appview:labels:stream does the same
| in the app.
|
| What a label does is decided per labeler, value and target (content or
| account), in this order: an operator's policy in atp_label_policies, the
| policies below, the labeler's own declaration, then the defaults for the
| values Bluesky defines. An effect is one of ignore, badge, warn, blur,
| hide, review and ban.
|
*/

return [
    // tack, or streams for appview:labels:stream, which needs
    // socialdept/atp-signals. It decides what following a labeler does.
    'source' => env('ATP_APPVIEW_LABELS_SOURCE', 'tack'),

    // How often, in seconds, appview:labels:stream rereads the labelers it
    // follows and the accounts the index knows.
    'streams' => [
        'refresh' => (int) env('ATP_APPVIEW_LABELS_STREAMS_REFRESH', 60),
    ],

    'tack' => [
        'url' => env('ATP_APPVIEW_TACK_URL'),
        // Tack's TACK_ADMIN_PASSWORD. It signs webhooks with it, and the app
        // uses it to reach Tack's admin API.
        'password' => env('ATP_APPVIEW_TACK_PASSWORD'),
    ],

    // Where Tack delivers labels by webhook. Point TACK_WEBHOOK_URL at this
    // path. Turn it off when running appview:labels:consume instead.
    'webhook' => [
        'enabled' => (bool) env('ATP_APPVIEW_TACK_WEBHOOK', true),
        'path' => env('ATP_APPVIEW_TACK_WEBHOOK_PATH', 'atp-appview/tack'),
        'middleware' => [],
    ],

    // Labels an author puts on their own records, in labels.values.
    'self_labels' => (bool) env('ATP_APPVIEW_SELF_LABELS', true),

    // App-wide decisions, by labeler DID or * for any, then value, then
    // target. Operators override these from the panel.
    //
    //     'policies' => [
    //         '*' => ['spam' => ['account' => 'review']],
    //         'did:plc:ar7c4by46qjdydhdevvrndac' => ['porn' => ['content' => 'hide']],
    //     ],
    'policies' => [],
];
