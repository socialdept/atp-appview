# Standing

Part of the [atp-appview docs](../README.md#documentation). Facts about accounts and records the index does not hold, whether an account is verified and by whom, and scores built from them.

Standing is a [feature](features.md), `Features::standing()`, off unless listed. It keeps three kinds of fact in `atp_standing`: metrics (how many follow an account, how many link to a record), verification, and a score per subject. A subject is an account (a DID) or a record (an AT-URI) of any collection. Where each fact comes from, what counts as verification, and how much each fact weighs are yours to choose.

## Metrics

```php
// config/atp-appview/standing.php
'metrics' => [
    'followers' => ['subject' => 'account', 'source' => 'backlinks', 'link' => 'app.bsky.graph.follow:subject'],
    'following' => ['subject' => 'account', 'source' => 'repo', 'collection' => 'app.bsky.graph.follow', 'cap' => 5000],
    'likes' => ['subject' => 'record', 'source' => 'index', 'link' => 'com.example.like:subject.uri'],
],
```

| Source | Counts | Cost |
|--------|--------|------|
| `index` | Records the index holds whose `link` path points at the subject, the way `Count` view parts count them. | Free and current, as complete as what you ingest. |
| `backlinks` | Links pointing at the subject across the network, from Constellation (`atp-support.microcosm.constellation.url`). | One request per subject. Covers what you do not ingest. |
| `repo` | Records of a collection in the subject's own repo, read from its PDS a page at a time, up to `cap`. | One request per hundred records. The only built-in way to count outgoing links, such as following. |

A class implementing `Standing\Contracts\Metric` is a source too. List it as `'source' => MyMetric::class`:

```php
use SocialDept\AtpAppview\Standing\Contracts\Metric;

class Members implements Metric
{
    public function subject(): string
    {
        return 'account';
    }

    public function measure(array $subjects): array
    {
        // subject => int|float, or null when unknown
    }
}
```

A value that cannot be measured (Constellation down, a PDS that does not answer) is unknown, not zero. The stored value stays, and the subject is tried again after `fresh.retry` seconds.

## Verification

A verifier decides whether accounts are verified, and says why:

```php
use SocialDept\AtpAppview\Standing\Contracts\Verifier;
use SocialDept\AtpAppview\Standing\Verification;

interface Verifier
{
    /** @return array<string, Verification> keyed by DID */
    public function verify(array $dids): array;

    /** Whether this DID is an issuer this verifier trusts. */
    public function trusts(string $did): bool;
}
```

A `Verification` has a `status` of `valid`, `invalid` or `none`, the individual `verifications` (issuer, record URI, creation time, whether each holds and why not), the `method` that produced it, and whether it is `complete`. An incomplete answer, where some candidate could not be checked, is tried again after `fresh.retry` seconds.

```php
'verification' => [
    'policy' => 'any',
    'verifiers' => [
        [
            'class' => RecordVerifier::class,
            'collection' => 'app.bsky.graph.verification',
            'issuers' => ['did:plc:z72i7hdynmk6r22z27h6tvur'],
        ],
        ['class' => LabelVerifier::class, 'labelers' => ['did:plc:...'], 'values' => ['verified']],
    ],
],
```

Under the `any` policy an account is verified when any verifier says so. Under `all`, every verifier has to. The result keeps each verifier's verifications, so an app can show who verified an account.

### RecordVerifier

Bluesky's model, for any collection. An issuer publishes a record in its own repo naming the account and the account's current details. The options:

- `collection`: the verification records' collection, `app.bsky.graph.verification` by default.
- `subject`: the path to the verified DID, `subject` by default.
- `issuers`: the DIDs it trusts, and classes that give them (below). Records from anyone else are ignored.
- `match`: record fields that must still equal the account's, `['handle' => 'handle', 'displayName' => 'displayName']` by default. A record whose handle or display name no longer matches is `invalid`, not `none`, with the field as its reason. They are compared exactly, as Bluesky's AppView compares them: no trimming, case folding or Unicode normalization, and an empty value never matches.
- `prove`: `true` by default. See below.

Candidates come from the index when you ingest the collection, current to the second, deletions included. Otherwise they come from Constellation, filtered to the trusted issuers.

**Proof.** A record found is not yet a record published. With `prove` on, each candidate is fetched from the issuer's own PDS with `com.atproto.sync.getRecord`. The CAR it returns holds the signed commit and the tree path to the record. atp-cbor's `RecordProof` checks the path from the commit's data root to the record, block by block, and the commit's signature is checked against the signing key in the issuer's DID document. Only a proven record counts. One that cannot be proven (a PDS down, a bad path, a wrong signature) is neither valid nor invalid, and the answer is incomplete, so it is tried again soon.

Proof needs `socialdept/atp-cbor` and the `gmp` extension, which atp-appview suggests rather than requires. With `prove` on and atp-cbor missing, the verifier refuses to run rather than quietly trust what it found. Set `prove` to `false` only for records you already trust, such as ones your own app ingests from a source you control.

The handle compared is the one the index holds for the account, or the one in its DID document when that handle resolves back to the same DID. The display name is the profile's, through [profiles](views.md#profiles).

**Trusted issuers from elsewhere.** Bluesky does not publish its list of trusted verifiers, and a community may keep its own. An issuer can be a class implementing `Standing\Contracts\TrustedIssuers`, which gives DIDs from wherever the app keeps them: a table its operators edit, a list a community publishes as a record, one refreshed from elsewhere. Its answer is cached for `issuers_cache` seconds, 300 by default:

```php
use SocialDept\AtpAppview\Standing\Contracts\TrustedIssuers;

class CommunityVerifiers implements TrustedIssuers
{
    public function dids(): array
    {
        return Verifier::query()->pluck('did')->all();
    }
}

'issuers' => ['did:plc:z72i7hdynmk6r22z27h6tvur', CommunityVerifiers::class],
```

**Impersonation.** As on Bluesky, an `impersonation` label on an account makes its verification `invalid` however many records hold, and a trusted verifier's `trustedVerifierStatus` `invalid` too. Only labels from the labelers you name count, with their signatures checked, so name your own moderation service. It applies as labels arrive, without waiting for a refresh, and needs [moderation](moderation.md):

```php
'verification' => [
    'impersonation' => ['labelers' => ['did:plc:ar7c4by46qjdydhdevvrndac'], 'values' => ['impersonation']],
],
```

### LabelVerifier

A label value from labelers you trust, through [moderation](moderation.md), such as a community verifier that publishes `verified` labels. The labels are already signature checked by the moderation pipeline. It needs `Features::moderation()`.

### Your own

Any class implementing `Verifier`, listed with its options, which arrive as the constructor's one argument: an organization's member list, a domain handle check, anything. Its `method` names it in the result.

## Scores

```php
'scores' => [
    'account' => [
        'verified' => 25,
        'followers' => ['weight' => 10, 'transform' => 'log10'],
        'ratio' => ['metric' => 'followers', 'transform' => 'ratio:following|log10', 'weight' => 5],
        'handle' => ['*.bsky.team' => 50],
    ],
    'record' => [
        'likes' => ['weight' => 1, 'transform' => 'log10'],
        'author' => 0.5,
    ],
],
```

Each entry names a metric (or `metric` names it, so one metric can count twice) and multiplies its value by `weight` after its transforms, applied left to right:

| Transform | Value |
|-----------|-------|
| `log10` | log₁₀(value + 1) |
| `sqrt` | √value |
| `cap:N` | at most N |
| `bool` | 1 when above zero, else 0 |
| `ratio:other` | value ÷ the `other` metric, skipped when that one is unknown or zero |

`verified` adds its weight when the account is verified. `handle` adds the weight of each pattern its handle matches. A record's `author` adds that share of its author's account score. An unknown metric adds nothing.

## Freshness

Pages never wait on the network. Reading a fact reads what is stored, and asks the queue to refresh what is missing or stale, once per subject per `fresh.retry` seconds:

```php
'fresh' => ['metrics' => 86400, 'verification' => 21600, 'retry' => 900],
'batch' => 25,
```

Refreshes run in `RefreshStanding` jobs of `batch` subjects. `php artisan appview:standing:refresh did:plc:...` refreshes subjects now, and `--stale` refreshes up to `--limit` stale ones, which [`Features::schedule()`](features.md#scheduling) runs every fifteen minutes.

## Reading it

```php
use SocialDept\AtpAppview\Standing\Standing;

$standing = app(Standing::class);

$standing->metricsFor([$did, $uri]);   // subject => [metric => value or null]
$standing->verification([$did]);       // did => Verification
$standing->trusted($did);              // whether a verifier trusts it as an issuer
$standing->scores([$did, $uri]);       // subject => float
```

With the feature on, [profiles](views.md#profiles) gain:

- `verification`, in Bluesky's `verificationState` shape: `verifiedStatus`, `trustedVerifierStatus` and each verification's `issuer`, `uri`, `isValid` and `createdAt`. Only when a verifier is configured, and, as on Bluesky, never for an account without a valid handle.
- `stats`, the account metrics. Full profiles only, not basic ones.

[Search](search.md) uses scores too: account search orders by score after the accounts the viewer follows, and an app's [Top ranker](search.md#latest-and-top) can weigh the author's score.

Off, nothing is kept or fetched, profiles are unchanged, and the `Standing` API refuses, naming the feature to add.
