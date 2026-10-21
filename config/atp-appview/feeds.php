<?php

/*
|--------------------------------------------------------------------------
| Feeds
|--------------------------------------------------------------------------
|
| Custom feeds for the feeds feature, Features::feeds(), in the shapes of
| Bluesky's feed generators. The names are Bluesky's by default, a working
| example. An app serving feeds of its own lexicons renames them all, keeps
| the shapes, and runs php artisan appview:feeds:lexicons to write its own
| lexicon files.
|
| A feed is a class implementing Feeds\Contracts\Feed, listed under the
| rkey of its generator record. php artisan appview:feeds:publish writes
| those records to the publisher's repo.
|
*/

return [
    // The service entry added to the AppView's DID document. Renamed for
    // an app's own lexicons, for example
    // ['id' => 'standardsite_feedgen', 'type' => 'StandardSiteFeedGenerator'].
    'service' => ['id' => 'bsky_fg', 'type' => 'BskyFeedGenerator'],

    // The lexicons for the primitive, each in Bluesky's shape. Null leaves
    // an endpoint off. Bluesky's getFeed is served by its AppView rather
    // than a generator, so the hydrated feed is off until the app names it
    // in its own namespace.
    'lexicons' => [
        'generator' => 'app.bsky.feed.generator',
        'defs' => 'app.bsky.feed.defs',
        'skeleton' => 'app.bsky.feed.getFeedSkeleton',
        'describe' => 'app.bsky.feed.describeFeedGenerator',
        'interactions' => 'app.bsky.feed.sendInteractions',
        'feed' => null,
    ],

    // Feeds this service runs, by the rkey of their generator record.
    //
    //     'same-vibe' => App\Feeds\SameVibe::class,
    'feeds' => [],

    // The account whose repo holds the generator records, and the app
    // password appview:feeds:publish signs in with.
    'publisher' => [
        'did' => env('ATP_APPVIEW_FEEDS_PUBLISHER'),
        'password' => env('ATP_APPVIEW_FEEDS_PUBLISHER_PASSWORD'),
    ],

    // Policies describeFeedGenerator links to.
    'links' => [
        'privacyPolicy' => null,
        'termsOfService' => null,
    ],

    // How long interactions such as "show less" are kept, in days.
    'interactions_days' => 90,
];
