<h1 align="center">ATP AppView</h1>

<h3 align="center">
    Build lexicon-driven AT Protocol AppViews in Laravel.
</h3>

<p align="center">
    <br>
    <a href="https://packagist.org/packages/socialdept/atp-appview" title="Latest Version on Packagist"><img src="https://img.shields.io/packagist/v/socialdept/atp-appview.svg?style=flat-square"></a>
    <a href="https://github.com/socialdept/atp-appview/actions/workflows/tests.yml" title="GitHub Tests Action Status"><img src="https://img.shields.io/github/actions/workflow/status/socialdept/atp-appview/tests.yml?branch=main&label=tests&style=flat-square"></a>
    <a href="LICENSE" title="Software License"><img src="https://img.shields.io/github/license/socialdept/atp-appview?style=flat-square"></a>
</p>

---

> [!WARNING]
> **In early development.** Nothing here is ready to use yet. Expect breaking changes until the first tagged release.

## What is ATP AppView?

**ATP AppView** turns a Laravel application into an AT Protocol AppView:

- **Ingest:** sync any set of collections from the network through Tap or Tab, in batches, without flooding the app during backfill.
- **Index:** keep every ingested record in the application's own database, queryable through Eloquent.
- **XRPC:** serve queries and procedures generated from lexicons, with custom handlers where the default is not enough.
- **Auth:** one guard that works whether OAuth tokens live on the server or in the browser, with service proxying in both cases.

It builds on [atp-client](https://github.com/socialdept/atp-client) for OAuth, service auth and forwarding, and on [atp-schema](https://github.com/socialdept/atp-schema) for lexicons.

## Installation

```bash
composer require socialdept/atp-appview
```

## The atp guard

The `atp` guard answers one question per request: which DID is the viewer, and how did they prove it. It returns your `User` model, so `auth()`, Gates and policies work unchanged.

Add it next to your session guard in `config/auth.php`:

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'atp' => ['driver' => 'atp', 'provider' => 'users', 'session_guard' => 'web'],
],
```

Your user model implements `HasAtpSession` from atp-client, returning its DID. The guard looks users up by that column on every request, so give it a unique index.

### Resolution order

1. **A bearer JWT** is verified as service auth with atp-client: the signature against the issuer's DID document, expiry, `ATP_SERVICE_AUTH_AUDIENCE` (a bare DID also matches a combined `did#serviceId`), and the route's NSID as `lxm`. A bearer that fails is a 401 and never falls back to the session. OAuth access tokens sent by mistake are refused.
2. **The session guard** supplies the user when there is no bearer.
3. **Neither** leaves the viewer anonymous.

### Protecting XRPC routes

```php
Route::get('/xrpc/com.example.getTimeline', GetTimeline::class)
    ->middleware('atp.viewer:required,com.example.getTimeline');

Route::get('/xrpc/com.example.getThread', GetThread::class)
    ->middleware('atp.viewer:optional,com.example.getThread');
```

The first argument is `required` or `optional`. The second is the NSID the route answers to, which a service auth token must be bound to: a route without one accepts the session only. Errors use the XRPC shape, such as `{"error": "AuthenticationRequired", "message": "..."}`. Plain `auth:atp` works too, for routes that only need a user.

### The viewer

```php
$viewer = $request->viewer();

$viewer->did;              // who is calling
$viewer->source;           // ViewerSource::Session or ViewerSource::ServiceAuth
$viewer->method;           // the NSID a service auth token was bound to
$viewer->user();           // the local user, if any
$viewer->isService();      // true for a did#serviceId issuer
$viewer->canActOnRepo();   // true when the server holds a session for this DID
$viewer->client();         // Atp::as($did), or null in frontend mode
```

Write to the user's repo only when `canActOnRepo()` is true. In frontend mode the browser holds the tokens, writes to the repo itself, and the AppView changes only its own state.

### Provisioning and services

A verified DID with no local user gets one keyed by `did_column` when `provision` is `create`, the default. Users are only ever created from a verified credential, never from a DID in a request body. Set `provision` to `none` to leave the viewer without a user instead.

An issuer with a fragment, such as `did:web:feed.example#bsky_fg`, is a service rather than a person. It is only accepted when listed in `auth.trusted_services`, and is never provisioned.
## Ingest

`php artisan appview:consume` reads [Tap](https://github.com/bluesky-social/indigo/tree/main/cmd/tap)'s acknowledged websocket channel in batches and hands each batch to your handlers. Tap does the network work: crawling, fetching repos, verifying commits and ordering events per repo. The worker only writes what arrives.

```env
ATP_APPVIEW_TAP_URL=ws://localhost:2480/channel
ATP_APPVIEW_TAP_ADMIN_URL=http://localhost:2480
```

### Why it does not flood

Tap's webhook mode sends one HTTP request per record, which buries a PHP application during a backfill. The websocket channel is acknowledged instead: Tap stops sending once its outbox of unacknowledged events is full, and the worker acknowledges a batch only after every handler has committed it. While a batch is being handled the worker reads nothing, so the database sets the pace.

1. Events are buffered until `batch_size` arrive (200) or `flush_ms` passes (500).
2. Every handler receives the batch, in order.
3. Each event is acknowledged.
4. A failing batch is retried `max_attempts` times (3) with backoff. After that the worker reconnects without acknowledging it, and Tap redelivers it.

Delivery is at least once and Tap keeps events for one repo in order, so handlers must apply a batch idempotently. Running several workers is fine: Tap shards events across connected clients.

### Running the worker

`appview:consume` is a long-running process, like `queue:work`, so run it under a supervisor that restarts it when it exits: an entry in `solo.yml` locally, and a daemon or background process in production.

```bash
php artisan appview:consume --max-time=3600 --memory=128
```

| Option | Default | Exits when |
| --- | --- | --- |
| `--max-time` | `0` (off) | it has run for this many seconds |
| `--max-batches` | `0` (off) | it has handled this many batches |
| `--memory` | `128` | its memory reaches this many megabytes |

Run `php artisan appview:restart` at the end of a deploy, the same as `queue:restart`. Running workers notice within one `flush_ms` and exit, and their supervisor starts them on the new code. The worker also turns off the query log on every connection, which tools such as Telescope switch on.

Exiting is always lossless. The batch in progress finishes first, and anything not yet acknowledged is redelivered by Tap when the worker comes back.

### Handlers

```php
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;

class IndexPosts implements BatchHandler
{
    public function handle(Batch $batch): void
    {
        foreach ($batch->records() as $event) {
            // $event->uri(), $event->action, $event->record, $event->isBackfill()
        }

        foreach ($batch->identities() as $event) {
            // $event->did, $event->handle, $event->active
        }
    }
}
```

Register handlers in `atp-appview.ingest.handlers`. Write each batch in bulk rather than one query per event.

### Backfill status

Tap marks each record event: `live` false while it backfills a repo, true for commits arriving in real time. It exposes no count of repos still waiting, so `IngestStats` reads the stream instead. A backfill is running while backfill events keep arriving.

```php
use SocialDept\AtpAppview\Ingest\IngestStats;

$stats = app(IngestStats::class);

$stats->backfilling();      // a backfill event arrived in the last minute
$stats->lastBackfillAt();   // when the last one arrived
$stats->lastLiveAt();
$stats->perMinute();        // ['backfill' => …, 'live' => …, 'identity' => …] over five minutes
```

That covers every backfill, including later ones such as repos `appview:discover` adds. Events are counted as Tap delivered them, before the blocklist, so a backfill of blocked repos still counts. The consumer writes its counts to the cache at most every five seconds, in per-minute buckets several consumers can share. A stopped consumer reads as not backfilling, so pair this with Tap's outbox size, which stays above zero while events wait for a consumer.

`TapAdmin::stats('resync-buffer')` is not a queue of repos. It counts live events Tap holds back for repos that are mid-backfill.

### Discovering repos

Tap follows new repos live only for its one `TAP_SIGNAL_COLLECTION`. `appview:discover` finds every repo holding records in each of your collections and has Tap backfill and follow them, so an AppView can index several collections on plain Tap:

```php
// config/atp-appview.php
'ingest' => [
    'collections' => ['app.example.post', 'app.example.comment', 'app.example.like'],
],
```

```bash
php artisan appview:discover            # every configured collection
php artisan appview:discover app.example.post --dry-run
```

It pages through `com.atproto.sync.listReposByCollection`, adds each repo once even when it holds several collections, and sends them to Tap 1,000 at a time. Collections must be exact NSIDs, since the endpoint takes no wildcards.

The source defaults to `https://relay1.us-east.bsky.network`, the same relay Tap uses. [Light Rail](https://lightrail.microcosm.blue) answers the same method and works with `--source` or `ATP_APPVIEW_DISCOVERY_URL`, though it currently reports somewhat fewer repos than the relay.

Repos that start using a collection later are only found on the next run, so schedule it:

```php
Schedule::command('appview:discover')->hourly();
```

Set `TAP_SIGNAL_COLLECTION` to whichever collection a new user creates first, and those repos are picked up live in between.

### Tap admin

`TapAdmin` wraps Tap's admin API: `addRepos()` to backfill and follow specific repos, `removeRepos()`, `info($did)`, `stats('repo-count')` and `healthy()`.

[Tab](https://tangled.org/pds.dad/tab), a Tap fork that adds multiple signal collections, speaks the same protocol and works unchanged.

## Blocklist

Some repos are spam, and some hosts bridge in content an AppView does not want. Block rules keep their records out of the index before any handler sees them:

```bash
php artisan appview:block did:plc:abc123 --note="known spammer"
php artisan appview:block '*.bridge.example' --collection=app.example.post
php artisan appview:block did:plc:abc123 --collection=app.example.graph.* --purge
php artisan appview:block --file=blocklist.txt --purge

php artisan appview:blocks                  # every rule and how many records it dropped
php artisan appview:unblock did:plc:abc123
```

A rule names a **subject**, either a DID or a PDS host, and a **collection**:

- A host can carry a wildcard. `*.bridge.example` covers `pds.bridge.example` and any other subdomain, but not `bridge.example` itself. A scheme or path is stripped, so `https://pds.example/` works too.
- The collection defaults to every collection. It can also be an exact NSID, or a prefix such as `app.example.graph.*`.

A file holds one rule per line, `subject [collection]`, with anything after `#` kept as the note:

```text
did:plc:abc123                         # known spammer
*.bridge.example  app.example.post     # bridged feeds
```

### Where rules apply

- **At ingest.** Matching record events are dropped before the handlers run, then acknowledged like any other, so Tap does not redeliver them. Identity events always pass, so handles stay current. A running consumer picks up a changed rule before its next batch.
- **In Tap.** A blanket rule, one covering every collection, has Tap stop following the repos it covers, so no backfill is spent on them. For a host, that is every repo whose PDS is already known to be on it. Tap adds repos back by itself, on a firehose commit to its signal collection or when its crawler enumerates, so whenever an event shows a blocked repo has returned, the consumer removes it again, at most once every ten minutes per repo.
- **In discovery.** `appview:discover` leaves out repos a blanket rule covers.
- **With `--purge`.** Records already indexed are deleted outright, not tombstoned, since their authors did not delete them.

Removing a rule has Tap backfill the repos it covered, since the records it dropped were never indexed. A repo Tap had stopped following is added back. One it still follows is removed and added again, which makes Tap backfill it from scratch. Tap picks pending repos in no set order (on SQLite, in practice, oldest first), so during a large backfill a re-added repo can wait behind every repo still pending.

### Host rules

Tap events carry only a DID, so host rules need each repo's PDS. It is resolved from the DID document, through the PLC directory for `did:plc` or `/.well-known/did.json` for `did:web`, many at a time, and kept on `atp_identities` for `blocklist.pds_ttl` seconds (a day). Nothing is resolved while no host rule exists.

A DID document that cannot be fetched never blocks a repo: its records pass, and it is tried again once the TTL passes. Purging under a host rule first resolves every indexed repo whose PDS is not known yet. Around 3,000 repos take a little over a minute.

## Index

`RecordIndexer`, the default ingest handler, keeps every ingested record in your own database, in `atp_records` and `atp_identities`. Run `php artisan migrate` to create them. Postgres, MySQL, MariaDB and SQLite are supported, and `ATP_APPVIEW_INDEX_CONNECTION` puts the index on a connection of its own.

```php
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;

Record::collection('app.example.post')->by($did)->latest('sort_at')->get();

$record->record;              // the record body as an array
$record->identity->handle;    // the author, when the index has seen their identity
Identity::find($did)->records;
```

### Sort order

`sort_at` is when a record was made, as far as the index can trust it. It is the record's `createdAt`, or the timestamp inside a TID record key when there is no usable `createdAt`, but never later than the moment the record was indexed. A future `createdAt` therefore cannot pin a record to the top of a feed, and a backfill still lands years of history in the order it was written rather than all at once. Records with neither fall back to the index time.

### Indexes

Each index on `atp_records` serves a specific read:

| Index | Serves |
| --- | --- |
| `uri` (primary key) | Fetching one record, and the upsert's conflict check |
| `collection, sort_at, uri` | A collection newest first, and the cursor seeking straight to its page |
| `did, collection, sort_at, uri` | One repo's records in a collection, newest first, and purging a repo |
| `collection, indexed_at` | What arrived recently |

A cursor adds a plain `sort_at` range next to the tie-breaking comparison on `uri`, so the index seeks to the cursor rather than scanning every row before it. On 220,000 documents, a page 50,000 records deep takes 0.2 ms to execute.

### Idempotent writes

Tap delivers at least once, so every write can safely happen twice:

- A record is only overwritten by an event with the same or a newer revision. The revision is the repo commit's `rev`, a TID that the repository spec requires to increase within a repo. TIDs are 13 characters from an alphabet in ASCII order, so the `rev` column's binary collation compares them in time order. Every comparison is between versions of the same record, so it never crosses repos.
- A delete becomes a tombstone that keeps the delete's revision and clears the body. An older create replayed during a backfill cannot bring the record back, and queries skip tombstones unless you call `withTrashed()`.
- Several events for one record in a batch collapse to the last, which Tap's per-repo ordering makes the newest.

Each batch is written with one statement per 500 records, inside a single transaction.

Backfilled events all carry the repo's revision at the time Tap exported it, not the revision of the commit that last changed each record. Anything Tap delivers live afterwards has a newer revision, so the guard orders them correctly.

### Known limitation

When Tap resyncs a repo after losing track of it, it emits creates and updates for what the repo holds now, but no deletes for records removed in the meantime. A record deleted while Tap was out of sync for that repo stays in the index until something reconciles it against the repo. Reconciliation is planned.

## XRPC

Every class carrying the `#[Xrpc]` attribute becomes a route at `/xrpc/{nsid}`. Whether it is a query (GET) or a procedure (POST) comes from its lexicon, loaded through atp-schema from `resources/lexicons`.

```php
namespace App\Http\Xrpc;

use SocialDept\AtpAppview\Xrpc\Attributes\Xrpc;
use SocialDept\AtpAppview\Xrpc\Auth;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

#[Xrpc('com.example.getTimeline', auth: Auth::Required)]
class GetTimeline
{
    public function __invoke(XrpcRequest $request): array
    {
        $limit = $request->param('limit', 50);   // an int, as the lexicon declares
        $viewer = $request->viewer();

        return ['feed' => []];
    }
}
```

Handlers live in `app/Http/Xrpc` beside your controllers, since they handle incoming requests the same way, and are discovered there automatically. `atp-appview.xrpc.handlers` registers others, and `php artisan appview:routes` lists them.

- **Auth.** `Auth::Required` refuses anonymous requests, `Auth::Optional` (the default) resolves the viewer when there is one, and `Auth::None` skips the guard. Required and optional routes bind service auth tokens to the method's NSID.
- **Parameters** are read from the raw query string and cast to the lexicon's types, so `limit=25` arrives as `25` and a repeated `uris=a&uris=b` as an array. They are then validated against the lexicon's constraints.
- **Input.** A procedure's JSON body is validated against its lexicon's input schema and available as `$request->input()`.
- **Responses.** Return an array, `Arrayable`, `JsonSerializable`, `Responsable` or `Response`, or null for an empty 200. Throw `XrpcException` for an XRPC error such as `{"error": "NotFound", "message": "..."}`. Invalid parameters or input answer `InvalidRequest`.
- **Injection.** Handlers are resolved from the container and can type-hint `XrpcRequest`, `Viewer`, `Request` or any service.
- **Unknown methods** answer `MethodNotImplemented`, and a known method called with the wrong HTTP method answers `InvalidRequest` naming the right one. Your own routes under `/xrpc` still take precedence.
- **Lexicons load lazily.** Each route accepts GET and POST, and the controller refuses the verb the lexicon does not allow, so registering routes at boot reads no lexicon and touches no cache. A method's lexicon loads the first time it is called. `php artisan appview:routes` loads them all, and is where a misconfigured mapping shows up before traffic does.

### Default queries

A query that only lists or fetches one collection needs no handler class. Map it to the collection in `atp-appview.xrpc.collections`, give it a lexicon, and it is served straight from the index:

```php
'collections' => [
    'com.example.listThings' => 'com.example.thing',
],
```

The responses follow `com.atproto.repo.getRecord` and `listRecords`:

- **With `uri`**, it answers `{"uri", "cid", "value"}` for that record, or `RecordNotFound` when the index does not hold it in this collection.
- **Otherwise** it answers `{"records": [...], "cursor"}`, newest first by `sort_at`. `limit`, `cursor` and `reverse` page through the list, and `did` or `actor` (a handle the index knows) narrow it to one repo.

Declare those parameters in the lexicon, whose `limit` default and maximum apply. The cursor is opaque and keyset based, so pages never skip or repeat a record while new ones arrive. Default queries are public (`Auth::None`), and a handler class for the same method takes precedence. Procedures still need a handler class.

### Middleware

Add `'web'` to `atp-appview.xrpc.middleware` so session viewers resolve in backend mode. A handler without a lexicon declares `type: 'query'` or `type: 'procedure'` on the attribute.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
