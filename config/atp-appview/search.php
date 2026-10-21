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
    // Top ranks the recent matches, within window_days. counts names the
    // records pointing at a match to count for the ranker (collection =>
    // [paths]), which also sees the sources that matched.
    //
    //     'com.example.post' => [
    //         'sources' => [
    //             'text' => ['text'],
    //             'tags' => ['tags', 'facets.*.features.*.tag'],
    //         ],
    //         'top' => [
    //             'counts' => ['com.example.like' => ['subject.uri']],
    //             'window_days' => 7,
    //         ],
    //     ],
    'collections' => [],

    // How Top orders the matches: a class implementing
    // Search\Contracts\TopRanker. The default, RecentEngagement, counts the
    // sources matched and the records pointing at a match, decayed with age.
    // An app's own can weigh anything else, such as whether the viewer
    // follows the author.
    'ranker' => SocialDept\AtpAppview\Search\Rankers\RecentEngagement::class,

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
