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

Your user model implements `HasAtpSession` from atp-client, returning its DID.

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

## Index

`RecordIndexer`, the default ingest handler, keeps every ingested record in your own database, in `atp_records` and `atp_identities`. Run `php artisan migrate` to create them. Postgres, MySQL, MariaDB and SQLite are supported, and `ATP_APPVIEW_INDEX_CONNECTION` puts the index on a connection of its own.

```php
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;

Record::collection('app.example.post')->by($did)->latest('indexed_at')->get();

$record->record;              // the record body as an array
$record->identity->handle;    // the author, when the index has seen their identity
Identity::find($did)->records;
```

### Idempotent writes

Tap delivers at least once, so every write can safely happen twice:

- A record is only overwritten by an event with the same or a newer revision. The revision is the repo commit's `rev`, a TID that the repository spec requires to increase within a repo. TIDs are 13 characters from an alphabet in ASCII order, so the `rev` column's binary collation compares them in time order. Every comparison is between versions of the same record, so it never crosses repos.
- A delete becomes a tombstone that keeps the delete's revision and clears the body. An older create replayed during a backfill cannot bring the record back, and queries skip tombstones unless you call `withTrashed()`.
- Several events for one record in a batch collapse to the last, which Tap's per-repo ordering makes the newest.

Each batch is written with one statement per 500 records, inside a single transaction.

Backfilled events all carry the repo's revision at the time Tap exported it, not the revision of the commit that last changed each record. Anything Tap delivers live afterwards has a newer revision, so the guard orders them correctly.

### Known limitation

When Tap resyncs a repo after losing track of it, it emits creates and updates for what the repo holds now, but no deletes for records removed in the meantime. A record deleted while Tap was out of sync for that repo stays in the index until something reconciles it against the repo. Reconciliation is planned.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
