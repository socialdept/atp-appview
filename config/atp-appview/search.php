<?php

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
|
| Search for the search feature, Features::search(): records of the
| collections named here, and accounts the index holds. Results come back
| through the views configured for each collection, filtered for the viewer
| as feeds are. The methods take Bluesky's search shapes under your names.
|
| Each source is kept in the index as words, so run php artisan
| appview:fields after changing them.
|
*/

return [
    // What is searchable in each collection. A source is a list of paths
    // into the record, or of classes implementing
    // Search\Contracts\SearchText that derive text from it. The source
    // named tags answers the tag filter.
    //
    // Top ranks recent matches, within window_days, by weighted signals:
    // records pointing at the match (counts: collection => [path => weight]),
    // the viewer following its author (following), and how many sources
    // matched, decaying with age.
    //
    //     'com.example.post' => [
    //         'sources' => [
    //             'text' => ['text'],
    //             'tags' => ['tags', 'facets.*.features.*.tag'],
    //         ],
    //         'top' => [
    //             'counts' => ['com.example.like' => ['subject.uri' => 1]],
    //             'following' => 5,
    //             'window_days' => 7,
    //         ],
    //     ],
    'collections' => [],

    // The methods, under your own names. Null leaves one off.
    //
    //     'records' => 'com.example.search.searchRecords',       // as searchPosts
    //     'accounts' => 'com.example.search.searchAccounts',     // as searchActors
    //     'typeahead' => 'com.example.search.searchAccountsTypeahead',
    'xrpc' => [
        'records' => null,
        'accounts' => null,
        'typeahead' => null,
    ],
];
