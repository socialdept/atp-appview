# Claims

Part of the [atp-appview docs](../../README.md#documentation). A verdict per record on a claim the record makes, such as owning the website it names, checked by a checker you write and optionally inherited from a parent record in the same repo.

Claims is a [feature](../getting-started/features.md), `Features::claims()`, off unless listed. It keeps one row per claiming record in `atp_record_claims`, checks it in the queue, and narrows queries with `Record::verified()`. Reads only look at stored verdicts, so a page never waits on the network. Left out, nothing is kept or fetched, and `verified()` refuses, naming the feature to add.

"Verification" in this package means accounts (see [Standing](standing.md)). Claims are about records.

## Checkers

A collection makes claims through a class implementing `Claims\Contracts\ClaimChecker`:

```php
use SocialDept\AtpAppview\Claims\Contracts\ClaimChecker;
use SocialDept\AtpAppview\Claims\Verdict;
use SocialDept\AtpAppview\Models\Record;

class SiteChecker implements ClaimChecker
{
    // The fields the verdict depends on, or null when the record claims nothing.
    public function claim(Record $record): ?array
    {
        return isset($record->record['url']) ? ['url' => $record->record['url']] : null;
    }

    // Where checking sends requests, so checks are grouped and rate limited by host.
    public function host(Record $record, array $claim): string
    {
        return parse_url($claim['url'], PHP_URL_HOST);
    }

    // A verdict per record, by URI. A record left out is unknown.
    public function check(array $records): array
    {
        // uri => Verdict::verified(), Verdict::unverified($reason) or Verdict::unknown($reason)
    }
}
```

`unverified` means the claim does not hold. `unknown` means the check could not tell, such as when the host is down or times out.

## Configuration

```php
// config/atp-appview/claims.php
'collections' => [
    'com.example.site' => ['checker' => SiteChecker::class],
    'com.example.post' => [
        'checker' => PostChecker::class,
        'inherit' => ['via' => 'site', 'trust_after' => 3, 'sample_rate' => 0.05, 'sample_every' => 604800],
    ],
],
'recheck' => ['verified' => 86400, 'unverified' => 21600],
'backoff' => [900, 3600, 21600, 86400],
'grace' => ['failures' => 3, 'seconds' => 259200],
'per_host_per_minute' => 60,
'batch' => 25,
```

Every setting outside `collections` is a default, and a collection can set its own.

| Setting | Meaning |
|---------|---------|
| `recheck` | Seconds until a record is checked again after a verdict. `unverified` also takes a ladder, such as `[21600, 86400, 604800, 2592000]`, stepped by failures in a row with the last step repeating. |
| `backoff` | Seconds to wait after each failed check in a row. The last step repeats. |
| `grace` | A record verified by its own check keeps its verdict through failed checks until both limits pass: this many failures in a row, over this many seconds. |
| `per_host_per_minute` | Records checked per host each minute, or null for no limit. The rest stay due for the next run. |
| `batch` | Records handed to `check()` at once. |

## Verdicts

| State | Meaning |
|-------|---------|
| `pending` | The claim is new or changed and has not been checked. |
| `verified` | The claim holds. |
| `unverified` | The claim does not hold. |

A record is written as `pending` when its claim is new, or when any field `claim()` returns changes, and its parent too for an inheriting collection. Changing any other field keeps the verdict. A record that is deleted, or stops making a claim, loses its row.

A record never verified fails at once on an `unverified` answer, and stays as it was on an `unknown` one. A record verified by its own check is demoted only once the failures pass the grace window, whatever their kind, and each failure moves it a step down the backoff ladder. A pass clears the failures.

A record that keeps failing its claim is usually a site that never set up the proof, so a ladder for `recheck.unverified` spaces those checks out instead of repeating them every few hours. The step counts failures of either kind in a row. A record that loses its verdict starts at the first step, since the failures it spent in grace are not held against it. A pass, or a change to the claim, also starts it again, and an edit that fixes the claim is checked at once anyway.

The method says how the verdict was reached: `direct` (its own check), `inherited` (its parent's verdict), or `sampled` (its own check while it could have inherited).

## Inheritance

A collection with `inherit` takes its verdict from the record its `via` path names, such as a post taking its site's. The parent:

- must be in the same repo. A `via` naming another account's record is ignored.
- must be verified by its own check, for its current claim.
- must have passed `trust_after` checks in a row.

Inheritance is resolved when you read, through a join to the parent's row. A new record inherits as soon as it is written, and a parent losing its verdict, or changing its claim, takes every inheriting record out of `verified()` at once, with no job run. A record whose own check failed stays out, whatever its parent.

When a refresh reaches an inheriting record whose parent is trusted, it makes no request. It marks the record `inherited` and looks again in `sample_every` seconds. Each time, with the odds of `sample_rate`, the record is checked itself instead. A failed sample marks that record `unverified` and resets its parent's passes, so none of the parent's records inherit until it passes `trust_after` direct checks again. A record whose parent is not trusted is checked directly.

## Refreshing

```bash
php artisan appview:claims:refresh --stale            # queue what is due
php artisan appview:claims:refresh at://did:plc:…/…   # queue these now
```

The feature schedules `appview:claims:refresh --stale` every fifteen minutes (see [Scheduling](../getting-started/features.md#scheduling)). It queues one `RefreshClaims` job per host, and a job is unique by host, so only one runs per host at a time. `app(Claims::class)->refresh($uris)` checks records now, without the queue.

## Reading it

```php
Record::collection('com.example.post')->verified()->get();
```

`verified()` keeps records verified by their own check (`direct` or `sampled`) and records inheriting from a trusted parent. It reads `atp_record_claims` alone, on the index connection.

### Cost

`verified()` adds one lookup on `atp_record_claims` per record it considers, and one on the parent's row for an inheriting record, both by the unique `uri` index. Measured on Postgres with synthetic data: 12,000 records that claim and 265,000 that inherit from them, one repo holding 76,000 of those:

| Query | Time |
|-------|------|
| The latest 50 documents, verified | 3 to 7 ms |
| One repo's latest 50, or the next 50 by cursor | 3 ms |
| The same repo 20,000 rows deep by offset | 1 s, against 18 ms without `verified()` |
| Counting every verified document | 0.5 s, against 43 ms without |

Page with a cursor on `sort_at`, as the package's views do, never a deep offset, which checks every row it skips. Counting verified records across a collection is a whole-table question, so cache it or keep it out of requests. A covering index on the claims table was tried and left out: it did not speed the deep page and made the count twenty times slower.

A refresh reads three times and writes one update per set of identical values, so a job of 500 inherited records is five queries. Ingesting a batch adds two queries for claims, whatever its size.
