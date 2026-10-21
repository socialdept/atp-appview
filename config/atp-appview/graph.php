<?php

/*
|--------------------------------------------------------------------------
| Social graph
|--------------------------------------------------------------------------
|
| Who follows and blocks whom, read from records the index keeps. Name the
| collection and the field a follow or block keeps its subject in, usually
| your app's own lexicon or another app's. Run appview:fields after setting
| one, so the field is kept for records already indexed. Null leaves it out.
|
|     'follows' => ['collection' => 'com.example.graph.follow', 'path' => 'subject'],
|     'blocks' => ['collection' => 'com.example.graph.block', 'path' => 'subject'],
|
| Bluesky's own graph works the same way, as app.bsky.graph.follow and
| app.bsky.graph.block, for an app with no graph of its own.
|
| Blocks apply both ways and mutes only to the muter, wherever records are
| listed for a viewer. Mutes are private, so they are kept by the app in
| atp_mutes and atp_muted_words, never as records.
|
*/

return [
    'follows' => null,

    'blocks' => null,

    // Where muted words are looked for: each collection's text, in named
    // sources of one or more fields. A viewer can mute a word in some
    // sources only, such as tags but not text. The words are kept in the
    // index, so run appview:fields after changing this.
    //
    //     'muted_words' => [
    //         'com.example.post' => [
    //             'text' => ['text'],
    //             'tags' => ['tags', 'facets.*.features.*.tag'],
    //         ],
    //     ],
    'muted_words' => [],
];
