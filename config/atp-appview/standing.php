<?php

/*
|--------------------------------------------------------------------------
| Standing
|--------------------------------------------------------------------------
|
| Facts about accounts and records the index does not hold itself, and a
| score built from them, for the standing feature, Features::standing().
| Values are kept in atp_standing and refreshed in the queue, so a page
| never waits on the network.
|
*/

return [
    // Numbers about a subject: an account (a DID) or a record (an AT-URI).
    // A source is index (counted from reference fields the index keeps),
    // backlinks (counted by Constellation), repo (records of a collection
    // in the subject's own repo, up to a cap) or a class implementing
    // Standing\Contracts\Metric.
    //
    //     'followers' => ['subject' => 'account', 'source' => 'backlinks', 'link' => 'app.bsky.graph.follow:subject'],
    //     'following' => ['subject' => 'account', 'source' => 'repo', 'collection' => 'app.bsky.graph.follow', 'cap' => 5000],
    //     'likes' => ['subject' => 'record', 'source' => 'index', 'link' => 'com.example.like:subject.uri'],
    'metrics' => [],

    // Whether an account is verified, and by whom. Each verifier is a class
    // implementing Standing\Contracts\Verifier, with its options. Under the
    // any policy an account is verified by any of them, under all by every
    // one.
    //
    //     [
    //         'class' => Standing\Verifiers\RecordVerifier::class,
    //         'collection' => 'app.bsky.graph.verification',
    //         'issuers' => ['did:plc:z72i7hdynmk6r22z27h6tvur'],
    //         'match' => ['handle' => 'handle', 'displayName' => 'displayName'],
    //     ],
    'verification' => [
        'policy' => 'any',
        'verifiers' => [],
    ],

    // How metrics and verification add up to a score, per subject kind. A
    // weight multiplies a value after its transform: log10, sqrt, cap:N,
    // bool, or ratio:other (this metric divided by another). handle weighs
    // handles matching a pattern. A record's author adds that share of its
    // author's score. An entry can name its metric, so one counts twice.
    //
    //     'account' => [
    //         'verified' => 25,
    //         'followers' => ['weight' => 10, 'transform' => 'log10'],
    //         'ratio' => ['metric' => 'followers', 'transform' => 'ratio:following|log10', 'weight' => 5],
    //         'handle' => ['*.bsky.team' => 50],
    //     ],
    //     'record' => ['likes' => ['weight' => 1, 'transform' => 'log10'], 'author' => 0.5],
    'scores' => [
        'account' => [],
        'record' => [],
    ],

    // How long values stay fresh, in seconds, and how long to wait before
    // trying again what could not be checked.
    'fresh' => [
        'metrics' => 86400,
        'verification' => 21600,
        'retry' => 900,
    ],

    // Subjects refreshed per queued job.
    'batch' => 25,
];
