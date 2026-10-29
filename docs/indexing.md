# Index

Part of the [atp-appview docs](../README.md#documentation). How records are stored, sorted, validated and reconciled, account status, and index stats.

`RecordIndexer`, the default ingest handler, keeps every ingested record in your own database, in `atp_records` and `atp_identities`. Run `php artisan migrate` to create them. Postgres, MySQL, MariaDB and SQLite are supported, and `ATP_APPVIEW_INDEX_CONNECTION` puts the index on a connection of its own. On MySQL and MariaDB, the connection's session timezone must be UTC, as the [installation](../README.md#installation) notes.

```php
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;

Record::collection('app.example.post')->by($did)->latest('sort_at')->get();

$record->record;              // the record body as an array
$record->identity->handle;    // the author, when the index has seen their identity
Identity::find($did)->records;
```

## Record events

Records arriving live are announced as Laravel events once their batch commits: `Index\Events\RecordIndexed` for a create or update, and `RecordRemoved`, with what the record held, for a delete. Backfills and replays do not dispatch them. See [Realtime](realtime.md#record-events).

## Sort order

`sort_at` is when a record was made, as far as the index can trust it: the first usable date among the collection's time fields, else the timestamp inside a TID record key, else the moment the record was indexed. It is never later than the moment the record was indexed, so a future date cannot pin a record to the top of a feed, and a backfill still lands years of history in the order it was written rather than all at once.

The time fields default to `createdAt`. Collections that name their date differently list their own, tried in order:

```php
// config/atp-appview.php
'index' => [
    'sort_fields' => [
        '*' => ['createdAt'],
        'site.standard.document' => ['publishedAt', 'createdAt'],
    ],
],
```

```bash
php artisan appview:resort                          # recompute every indexed record
php artisan appview:resort site.standard.document   # or some collections
```

Run `appview:resort` after changing the fields: new records use them straight away, existing ones keep their old `sort_at` until recomputed. It writes only rows whose value changes.

Dates are read as records actually write them: ISO 8601, a space in place of the `T`, or the date alone when the time part is unreadable. Dates that do not exist, and anything before 1970, count as missing. Record keys minted from an old date, as importers do, are trusted back to 1970 too.

## Validation

Records can be checked against their collection's lexicon before they are indexed:

```dotenv
ATP_APPVIEW_VALIDATE_RECORDS=drop   # off (default), log or drop
```

| Mode | Invalid records |
|---|---|
| `off` | Not checked |
| `log` | Indexed, counted and logged |
| `drop` | Left out of the index, counted and logged. An earlier valid version stays indexed |

Only collections with a lexicon atp-schema can load are checked, and deletes always go through. Fields a lexicon does not mention are allowed, since a newer version of a lexicon may add optional fields this app does not know yet, and refs to other definitions are not followed. Every path into the index is checked: Tap, the sync procedure and reconciliation. Each batch logs one line per collection with an example, and `RecordValidation::count($collection)` reports how many invalid records a collection has seen. Checking costs well under a tenth of a millisecond per record.

## Following published lexicons

An AppView that indexes other people's records can follow their lexicons instead of copying the files in by hand. List the NSIDs and schedule the sync:

```php
// config/atp-appview.php
'lexicons' => [
    'follow' => ['com.example.thing', 'com.example.feed.post'],
],
```

```php
Schedule::command('appview:lexicons:sync')->hourly();
```

The sync resolves each NSID through its `_lexicon` DNS record, stores it in `atp_lexicons` with its CID, and writes the version in use to `lexicons.path`. That path is added to atp-schema's sources after the app's own, so validation and XRPC load followed lexicons like local ones, and an app's own copy wins. `atp_lexicon_versions` keeps every version that was put in use.

A new version replaces the one in use only when atp-schema's breaking-change check passes. Otherwise it is held back with its findings, the one in use stays, and a warning is logged.

```bash
php artisan appview:lexicons                    # what is followed, and anything held back
php artisan appview:lexicons:accept com.example.thing
php artisan appview:lexicons:sync --force       # put held-back versions in use
```

The sync also asks Tap to follow each author's repo. With `com.atproto.lexicon.schema` in Tap's collection filters and `FollowedLexiconsHandler` in `ingest.handlers` (the default), a new version is synced as soon as its author publishes it. The event only says which NSID changed: the schema is still fetched through DNS, so a repo DNS does not name cannot publish a version this app uses. Long-running processes such as `appview:consume` read a new version once they restart.

A fresh server or deploy gets every followed lexicon from the database: each sync writes all of them again.

## Stored values

- **Postgres** stores `record` as `jsonb`, so fields can be read and indexed without re-parsing each document. [Retention](retention.md#compression) sets it to LZ4 compression.
- **NUL characters** in a record's keys and strings are stored as U+FFFD. Postgres cannot read a field from json holding an escaped NUL, nor store one as jsonb, so a single such record would otherwise break queries across the table or fail its batch forever. For those rare records the stored value no longer matches the record's CID.
- **MySQL and MariaDB** store the index's dates as `DATETIME`, not `TIMESTAMP`, which holds only 1970 to 2038 and converts through the session timezone.

## Indexes

Each index on `atp_records` serves a specific read:

| Index | Serves |
| --- | --- |
| `uri` (primary key) | Fetching one record, and the upsert's conflict check |
| `collection, sort_at, uri` | A collection newest first, and the cursor seeking straight to its page |
| `did, collection, sort_at, uri` | One repo's records in a collection, newest first, and purging a repo |
| `collection, indexed_at` | What arrived recently |
| `collection, sort_at, uri` where hidden | A collection's hidden records, newest first (Postgres and SQLite) |
| `collection, sort_at, uri` where tombstoned | A collection's tombstones, newest first (Postgres and SQLite) |

Hidden records and tombstones are a sliver of each collection. Through the main sort index, listing them walks the whole collection to find them: 406 ms for 19 hidden records among 229,000 documents. The partial indexes hold only that sliver, and the same list takes 0.1 ms. MySQL and MariaDB have no partial indexes, so they get plain indexes on the collection and `hidden_at` or `deleted_at` instead, and the same lists take about a millisecond.

On `atp_identities`, `reconciled_at` serves `appview:reconcile` picking the repos due a check. Postgres builds it nulls first, since the run starts with repos never checked.

`created_at` on `atp_identities` is when the index first saw the repo, by any route: an identity event, a Jetstream record from a new repo, or a PDS lookup for the blocklist. It is set when the row is added and never changed after, and its index serves questions like how many repos arrived this week.

```php
Identity::query()->where('created_at', '>=', now()->subWeek())->count();
```

Repos indexed before the column existed got the earliest time the index held for them: their oldest record's `indexed_at`, or the identity's `updated_at` when that is earlier. Both come after the repo was first seen, so for those repos the value can be late, never early.

A cursor adds a plain `sort_at` range next to the tie-breaking comparison on `uri`, so the index seeks to the cursor rather than scanning every row before it. On 220,000 documents, a page 50,000 records deep takes 0.2 ms to execute.

## Counts

`IndexStats` reports how many records, hidden records and tombstones each collection holds, and how many repos hold at least one record anyone can see:

```php
use SocialDept\AtpAppview\Index\IndexStats;

$stats = app(IndexStats::class);

$stats->collections(); // ['app.example.post' => ['records' => 229150, 'hidden' => 19, 'tombstones' => 45, 'repos' => 4763], ...]
$stats->totals();      // the same across every collection, each repo counted once
$stats->countedAt();   // when, or null before the first count
```

Counting repos reads every record, so the counts are taken by `appview:stats` and stored in `atp_index_stats` rather than worked out per request. Schedule it:

```php
Schedule::command('appview:stats')->everyFiveMinutes()->withoutOverlapping();
```

It makes one pass over the index, about 0.4 s for 265,000 records on Postgres, and prints what it counted. Counting on every write instead would have to follow each way a record changes state, and drift whenever one was missed. A recount cannot drift.

## Idempotent writes

Tap delivers at least once, so every write can safely happen twice:

- A record is only overwritten by an event with a newer revision, or the same revision and a different CID. The same record sent again unchanged, as Tap does when it resyncs a repo, leaves the row alone, so `indexed_at` and `sort_at` keep their values. The revision is the repo commit's `rev`, a TID that the repository spec requires to increase within a repo. TIDs are 13 characters from an alphabet in ASCII order, so the `rev` column's binary collation compares them in time order. Every comparison is between versions of the same record, so it never crosses repos.
- A delete becomes a tombstone that keeps the delete's revision and clears the body. An older create replayed during a backfill cannot bring the record back, and queries skip tombstones unless you call `withTrashed()`.
- Several events for one record in a batch collapse to the last, which Tap's per-repo ordering makes the newest.

Each batch is written with one statement per 500 records, inside a single transaction.

Backfilled events all carry the repo's revision at the time Tap exported it, not the revision of the commit that last changed each record. Anything Tap delivers live afterwards has a newer revision, so the guard orders them correctly.

## Reconciliation

When Tap resyncs a repo after losing track of it, it sends only the records whose CID it has not seen, and never a delete. A record removed while Tap was not following the repo would stay indexed, and a resync that only found deletions sends nothing at all, so the index cannot tell one happened.

`appview:reconcile` compares each repo with its own PDS instead. It reads the repo's latest revision, lists every collection in `ingest.collections` that the repo has, and then:

- tombstones an indexed record the PDS no longer has
- indexes a record the PDS has but the index lacks, or holds at another CID

Every write carries the revision read before listing, so nothing indexed at a newer revision, such as a record created or deleted while the listing ran, is touched. A listing that fails, or a collection past `index.reconcile.max_records`, writes nothing, since a partial listing would read as deletes. Blocked repos and collections are left alone.

What it finds goes through the ingest pipes and every handler, as a backfill from Tap would: ingest hooks reject records, repairs rewrite them, and features such as the graph and notifications see the changes as backfill (`isBackfill()`).

Schedule it and it works through the active repos, least recently checked first, `index.reconcile.batch` per run, checking each again after `index.reconcile.every_hours`:

```php
Schedule::command('appview:reconcile')->everyMinute()->withoutOverlapping();
```

Expect about two seconds a repo, nearly all of it waiting on the PDS. Each repo's last check is kept on `atp_identities`: `reconciled_at`, the `reconciled_rev` it compared against, and `reconcile_error` when the PDS could not be read.

```bash
php artisan appview:reconcile did:plc:abc -v   # one repo, listing every change
php artisan appview:reconcile --dry-run        # report without writing
```

## Account status

An account's status decides what happens to its records, whether Tap reports it as an account event or the PDS answers it during reconciliation.

| Status | Records | Comes back |
|---|---|---|
| Deleted | Tombstoned, like any deleted record | Restored by `appview:reconcile` if the repo turns out to exist |
| Deactivated, taken down, suspended | Hidden: queries skip them, and `withHidden()` brings them back | Shown again when the account is active |
| Inactive past `index.inactive.purge_after_days` (180) | Tombstoned by `appview:purge-inactive` | Restored by `appview:reconcile` once the account is active |

Tombstones keep each record's own revision, so a replayed older event still cannot bring a record back, but a listing from a repo that returns can. Before taking a repo its PDS cannot find as deleted, reconciliation resolves the DID again, since the account may have moved to another PDS. A takedown or suspension may be the relay's rather than the PDS's, so only Tap lifts those. Reconciliation checks deactivated and deleted repos, and skips taken down and suspended ones.

```php
Schedule::command('appview:purge-inactive')->daily();
```

## Status

`AppviewStatus` gathers what an operator wants to know, for a status page or an admin panel:

```php
use SocialDept\AtpAppview\Status\AppviewStatus;

$status = app(AppviewStatus::class);

$status->sources();         // where records and labels come from: tap or jetstream, tack or streams
$status->tap();             // Tap's health, repos followed, outbox, resync buffer, firehose cursor
$status->streams();         // Jetstream and label streams: running, last seen, cursors and when each was saved
$status->ingest();          // events a minute, live and backfill, and whether a backfill is arriving
$status->writes();          // rows written to the index lately, and the latest write
$status->recentRecords();   // records the network made in the last 24 hours, by their own timestamps
$status->collections();     // records, hidden, tombstones and repos per collection
$status->totals();          // the same across collections
$status->storage();         // the index's size on disk, on Postgres
$status->accounts();        // active accounts, and inactive ones by status
$status->reconciliation();  // repos checked recently, and reconcile errors grouped by kind
$status->lexicons();        // followed lexicons and versions held back
$status->validation();      // the validation mode, and invalid records per collection
$status->blocklist();       // rules, records dropped and the latest drop
```

Each figure is cheap. Whole-index counts come from `IndexStats`, kept by `appview:stats`, and the rest is cached for a few seconds, so a page that polls costs little. Tap is asked with a short timeout, and a Tap that is down is reported rather than thrown. A websocket source reads as running when its heartbeat is under 30 seconds old. Jetstream's cursor moves every few seconds, since it carries account and identity events from the whole network, so a stale `savedAt` there means trouble. A labeler can go quiet for hours, so an old cursor there means nothing on its own.

To reconcile one repo from a button and keep the outcome on its identity, the way the scheduled run does, call `Reconciler::reconcileAndRecord($did)`. `ReconcileResult::summary()` says what it found in one line, and `ReconcileErrors::kind()` groups errors such as "Host not found" or "Timed out". `FollowedLexicon` is the model for `atp_lexicons`.
