<?php

/*
|--------------------------------------------------------------------------
| Profiles
|--------------------------------------------------------------------------
|
| Where an account's profile comes from, in order. The first source with
| a record for the account is its profile, empty fields and all, unless
| merge is `field`, where each standard field takes the first source that
| has it. Every profile has displayName, description, avatar and banner. A
| source can add `fields` passed through as they are, and `aliases` for a
| lexicon naming the standard fields differently, or be set fluently:
| Profiles::source('com.example.profile')->fields(['pronouns']).
|
| Profiles come from the index for collections it ingests, usually as
| companion collections, and are fetched from Slingshot otherwise.
|
*/

return [
    'merge' => env('ATP_APPVIEW_PROFILES_MERGE', 'record'),
    'sources' => [
        ['collection' => 'app.bsky.actor.profile', 'fields' => ['pronouns', 'website']],
    ],
];
