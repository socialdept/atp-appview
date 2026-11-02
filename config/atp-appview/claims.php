<?php

/*
|--------------------------------------------------------------------------
| Claims
|--------------------------------------------------------------------------
|
| A verdict per record on a claim the record makes, such as owning the
| website it names, for the claims feature, Features::claims(). Verdicts
| are kept in atp_record_claims and checked in the queue, so a page never
| waits on the network.
|
*/

return [
    // The collections whose records make a claim, each with a class
    // implementing Claims\Contracts\ClaimChecker. Any setting below can be
    // given per collection too. A collection that inherits takes its
    // verdict from a parent record named at the via path, in the same repo,
    // once that parent has passed trust_after direct checks in a row. Every
    // sample_every seconds an inheriting record is checked itself with the
    // odds of sample_rate, and a failed sample stops the parent's records
    // inheriting until it passes trust_after checks again.
    //
    //     'com.example.site' => ['checker' => SiteChecker::class],
    //     'com.example.post' => [
    //         'checker' => PostChecker::class,
    //         'inherit' => ['via' => 'site', 'trust_after' => 3, 'sample_rate' => 0.05, 'sample_every' => 604800],
    //     ],
    'collections' => [],

    // Seconds until a record is checked again, by its verdict.
    'recheck' => [
        'verified' => 86400,
        'unverified' => 21600,
    ],

    // Seconds to wait after each failed check in a row, the last step
    // repeating.
    'backoff' => [900, 3600, 21600, 86400],

    // A record verified directly keeps its verdict through failed checks
    // until both limits pass: this many failures in a row, over this many
    // seconds.
    'grace' => [
        'failures' => 3,
        'seconds' => 259200,
    ],

    // Records checked per host each minute, or null for no limit. The rest
    // wait for the next run.
    'per_host_per_minute' => 60,

    // Records checked per call to a checker.
    'batch' => 25,
];
