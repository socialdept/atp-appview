# Retention

Part of the [atp-appview docs](../README.md#documentation). Keeping the index small: compressing stored records, pruning old tombstones, keeping some collections only while recent, and fetching old bodies again instead of storing them.

Everything here is off until set in `config/atp-appview/retention.php`, so an app that sets nothing keeps every record as it is.

## Pruning

`appview:prune` removes what retention lets go: old [tombstones](#tombstones), then records past their collection's [max age](#max-age), then the bodies of [cold records](#cold-records). Schedule it:

```php
Schedule::command('appview:prune')->everyFiveMinutes()->withoutOverlapping();
```

```bash
php artisan appview:prune --dry-run   # count what would go, per collection
```

It reads a chunk of rows at a time (`--chunk`, 1,000 by default) through an index on their collection and age, deletes them by primary key in a transaction of their own, and stops after `--seconds` (50 by default), so no run holds a lock for long and the next one carries on. With nothing configured it reads nothing and deletes nothing. It works on `atp-appview.index.connection`.

A deleted row takes with it what references it: its [field values](views.md#fields), moving the kept counts, and its notifications. Features keep their own tables in step through `afterPrune()`, as [writing a feature](features.md#writing-a-feature) describes. Labels about a record are the labeler's, so they stay. Pruning dispatches no record events, since nothing changed on the network, and the counts from `appview:stats` catch up on its next run.

## Compression

Postgres compresses a large value before storing it, with pglz unless the column says otherwise. LZ4 compresses and decompresses faster, at a similar size for JSON. Set it for the columns holding record bodies, `record` and `repair` on `atp_records`, and on `atp_space_records` when [spaces](spaces.md) are on:

```dotenv
ATP_APPVIEW_RECORD_COMPRESSION=lz4
```

```bash
php artisan appview:compress --dry-run   # what would change
php artisan appview:compress             # set the columns
```

It needs Postgres 14 or later, built with LZ4, and the command says so and fails otherwise. Setting a column's compression changes the catalog only: it takes a brief lock and rewrites nothing. Run the command again after `migrate:fresh`, or set the variable back to empty and run it to return the columns to the server's default.

Only values written from then on use LZ4. Rows already stored keep pglz until rewritten, and values under about 2 KB are stored uncompressed either way. To rewrite the older rows:

```bash
php artisan appview:compress --recompress --seconds=300
```

It works through each table in id order, a chunk at a time (`--chunk`, 1,000 by default), and rewrites only rows whose values use another compression, so a run stopped at `--seconds` picks up where it left off next time. Assigning a column to itself would keep the stored value as it is, so each value is rebuilt, which costs:

- **Space before it saves any.** Every rewritten row is a new row version, and the old ones stay on disk until vacuumed. Plain `VACUUM` (or autovacuum) makes that space reusable, but only `VACUUM FULL` or `pg_repack` returns it to the operating system.
- **Write-ahead log** about the size of what is rewritten, which replicas and backups receive too.
- **Row locks** on each chunk while it is written, so ingest touching the same rows waits for that chunk.

On 50,000 synthetic 3 KB documents, LZ4 stored 13 MB against pglz's 16 MB, rewriting them took about 0.5 s against 1.5 s for pglz, and reading every body took the same time with either. Real records vary, so compare with `pg_column_size(record)` on your own data before rewriting a large index.

### Other databases

| Database | Behavior |
| --- | --- |
| Postgres 14+ with LZ4 | Columns set by `appview:compress` |
| Postgres before 14, or without LZ4 | `appview:compress` fails and says why |
| MySQL | Nothing. InnoDB stores JSON in a binary format of its own, and its compression is a table option (`ROW_FORMAT=COMPRESSED`, or page compression that depends on the filesystem's hole punching), which rebuilds the table and is the operator's choice |
| MariaDB | Nothing. Its `COMPRESSED` column attribute rebuilds the table to add and stops the column from being indexed |
| SQLite | Nothing. It has no column compression |

The command reports that there is nothing to do and succeeds, so the same deploy script runs everywhere.

## Tombstones

A deleted record stays in the index as a tombstone, a row with no body that keeps the delete's revision. It is what stops an older copy of the record from bringing it back, since a write only lands with a newer revision. Tombstones are small, but a collection with many deletes, such as likes, collects them for good. Let them go after a number of days, per collection, with `'*'` for the rest:

```php
// config/atp-appview/retention.php
'tombstones' => [
    '*' => 180,
    'app.example.like' => 30,
    'app.example.post' => null,   // kept
],
```

Or `ATP_APPVIEW_TOMBSTONE_DAYS=180` for the default alone. Age is `deleted_at`, when the index recorded the delete, so a tombstone written by a backfill or a reconcile counts from then, not from when the author deleted the record.

A tombstone already holds no field values or notifications, since a delete clears them. Those left by an account deletion or a purge go with it.

### What a pruned tombstone no longer stops

Once a tombstone is gone, nothing in the index remembers the record, so an older copy of it arriving later would index it again. Each way a record arrives was checked:

| Source | Why it cannot bring a pruned record back |
| --- | --- |
| Tap redelivering a batch | Tap redelivers within minutes, far inside any window |
| Jetstream after a restart | Its cursor replays what Jetstream still holds, a day or so, not months |
| A backfill, Tap's or from a PDS | It reads the repo as it is now, which no longer holds the record |
| `appview:reconcile` | The same, and it tombstones anything the repo lacks |
| `appview:failed-deliveries --retry` | A create or update parked longer than its collection's window is reindexed from the PDS instead of retried, so it lands as the repo has it now |

What remains is a copy older than the window from somewhere the package does not know about, such as an archive of the firehose replayed by hand. The record would show again until `appview:reconcile` next checks its repo and tombstones it. Keep the window well past any replay you run, and keep reconcile scheduled.

### Indexes

| Database | Index read |
| --- | --- |
| Postgres, SQLite | A partial index on `collection, deleted_at` holding only tombstones |
| MySQL, MariaDB | The plain `collection, deleted_at` index they already have |

The list of collections comes from one seek per collection along the collection index, so finding them never reads the table.

## Max age

Some collections only matter while recent, such as read receipts or presence. Name them with the number of days to keep, and `appview:prune` deletes older records outright, row and all, with what references them:

```php
// config/atp-appview/retention.php
'max_age' => [
    'app.example.read' => 30,
],
```

There is no `'*'`, and one set is ignored: no collection is ever aged unless named.

Age is `sort_at`, when the record was made as far as the index can trust it ([sort order](indexing.md#sort-order)):

- `indexed_at` would count a backfilled receipt from a year ago as new, and keep it for another whole window.
- `created_at` is not a column, and the record's own date is what `sort_at` already reads, capped at the time it was indexed, so a record claiming a future date ages from when it arrived.
- It is what the collection is listed by, so the oldest go first and the read uses the `collection, sort_at, uri` index every database already has. No migration is needed.

A record whose author dated it in the past ages from that date. It is their record saying how old it is. After changing `index.sort_fields`, run `appview:resort` before pruning, so the ages are read from the new fields.

Tombstones in the collection go by the same `sort_at`. Their delete would only stop an older copy of a record already past the window, which ingest leaves out anyway.

### Ingest leaves them out

A record already older than the window when it arrives is not indexed only to be pruned: Tap, Jetstream, backfills, `appview:reconcile`, the sync procedure and failed delivery retries all go through the same check, by the `sort_at` the record would get. It dispatches no record event, and handlers that read what the index stored, as notifications do, find nothing. A handler of the app's own that reads the batch's events directly still sees it, and can ask `Retention::outlived()`. Deletes still go through, so a delete of a recent record leaves its tombstone. `appview:reconcile` does not count the records it leaves out as indexed, so it does not report them on every run.

## Cold records

Most of a record row is its body, and old records are read far less than new ones. A collection can let its old bodies go and fetch them again when read:

```php
// config/atp-appview/retention.php
'cold' => [
    '*' => 365,
    'app.example.like' => null,   // kept whole
],
```

Or `ATP_APPVIEW_COLD_AFTER_DAYS=365` for the default alone. `appview:prune` clears the body of each live record whose `sort_at` is older than the window, the same age [max age](#max-age) reads, and marks the row with `hollowed_at`. Everything else stays: the uri, CID and dates, its [field values](views.md#fields) and the counts they keep, the [graph](social-graph.md) edges, labels and notifications. Lists, filters, counts and joins answer as before, since none of them read the body.

A record an ingest [repair](ingest.md#ingest-repairs) rewrote keeps its body, since a fetch would return the unrepaired one. Each collection's pass keeps its place in the cache and starts over once a day, so a run reads only rows that aged since, and a record a backfill indexes with an older date is picked up the next day.

### Reading a cold record

When something reads a body, the page's cold records are fetched together: one cache read, then one parallel round of `com.atproto.repo.getRecord` calls for what the cache lacks, asking for the stored CID. They go to Slingshot by default, or to each record's own PDS:

```dotenv
ATP_APPVIEW_COLD_FETCH_FROM=pds        # slingshot by default
ATP_APPVIEW_COLD_FETCH_TIMEOUT=5       # seconds per request
ATP_APPVIEW_COLD_FETCH_CACHE_TTL=86400 # seconds a fetched body is cached
```

A fetched body is used only when the response carries the stored CID. With `socialdept/atp-cbor` installed, the body's own CID is computed from its DAG-CBOR form and must match too, so neither Slingshot nor a PDS can hand back another body. A body holding bytes or a float cannot be carried faithfully through JSON, so for those only the response's CID is compared. Bodies are cached by CID, so each version is fetched once, and a failed fetch is remembered for five minutes so a missing record does not cost a request on every page.

A record whose body cannot be fetched, because its PDS is down or it was deleted there, is handled by what reads it:

| Reader | A cold record |
| --- | --- |
| XRPC lists, default and [view](views.md) queries | Fetched with the page. One that cannot be fetched is left out, and the cursor still moves past it |
| XRPC get by `uri` | Fetched, or `502 UpstreamFailure` |
| `Hydrator`, embeds, `ReplyTree::parents()` | Fetched with the page. One that cannot be fetched keeps its place with a null `record` |
| [Profiles](views.md#profiles) | Fetched. One that cannot be fetched is asked of Slingshot like any profile the index lacks |
| Field values rebuild, graph subjects, standing verifiers | Fetched a chunk at a time, and given no values when it cannot be |
| Notifications as records arrive | Kept as they are. A cold record's notifications were built from its body |
| Notification rebuilds and replays, self-label rebuild, `appview:hooks:apply` | Skipped. They would fetch the whole collection, and what they built before stays |
| A delete's `RecordRemoved` event | The body from the cache when it is there, null otherwise. Nothing is fetched for a record being deleted |
| `appview:resort` | Skipped, as it skips any row without a body |

Code of the app's own that reads `$record->record` gets null for a cold record. `$record->isCold()` says so, and `app(ColdRecords::class)->thaw($records)` fills in a page of them at once, models or query rows selected with `uri`, `cid`, `record` and `hollowed_at`.

A query on the body itself, a `where('record->...')`, cannot match a cold record. Read through [field values](views.md#fields) instead, or keep that collection whole. A newer version of the record stores its body again.

### Space reclaimed

| Database | When the space comes back |
| --- | --- |
| Postgres | Plain `VACUUM` (or autovacuum) makes it reusable by new rows. Only `VACUUM FULL` or `pg_repack` returns it to the operating system |
| MySQL, MariaDB | InnoDB reuses it for new rows. `OPTIMIZE TABLE atp_records` rebuilds the table to shrink the file |
| SQLite | Reused by new rows. `VACUUM`, or `auto_vacuum`, shrinks the file |

Clearing a body is an update, so it writes a row version and write-ahead log like any other. [Spaces](spaces.md) records are never hollowed.

