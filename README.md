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

## Frontend and backend mode

Where the user's OAuth tokens live decides how records get written.

| | Frontend mode (default) | Backend mode |
|---|---|---|
| Tokens | In the browser, with a non-extractable DPoP key | On the server |
| Writing records | The browser calls `com.atproto.repo.*` on its own PDS | Default procedures, through the server's session |
| Reaching the AppView | Through the PDS, with `atproto-proxy` and service auth | The session cookie, or service auth |
| Seeing your own write | `sync_record`, or show it optimistically | Indexed as part of the write |

Frontend mode is the default and the safer choice: the server stores no tokens, and it is how atproto apps such as Bluesky's own work. Choose backend mode only when the app must act for users while they are away, such as scheduled publishing. `atp-appview.auth.mode` records the choice.

## Frontend sign-in

In frontend mode the package ships both halves of sign-in: routes on the server and a browser library that holds the session. The browser keeps the OAuth tokens and a non-extractable DPoP key in IndexedDB, runs the authorization request, the token exchange and refreshes itself, and writes straight to its own PDS. The server never holds a token. It keeps the PKCE verifier between redirect and callback, signs short-lived client assertions so the app stays a confidential client, resolves accounts, and starts an ordinary session from a service auth token the user's own PDS signs.

### Server

The routes register in frontend mode. Set the method a service auth token must be bound to, under a domain you control:

```dotenv
ATP_APPVIEW_SESSION_METHOD=com.example.createSession
ATP_SERVICE_AUTH_AUDIENCE=did:web:example.com
```

| Route | Name | Purpose |
|---|---|---|
| `POST /oauth/prepare` | `atp.oauth.prepare` | Start a sign-in from a provider, a handle, or a known DID |
| `POST /oauth/client-assertion` | `atp.oauth.client-assertion` | Sign an assertion for the flow in progress or the user's own server |
| `GET /oauth/callback` | `atp.oauth.callback` | Check `state` and `iss`, hold the code, redirect to `complete_route` |
| `POST /oauth/flow` | `atp.oauth.flow` | Hand the browser the held flow, once |
| `GET /oauth/account` | `atp.oauth.account` | Resolve an account's PDS, issuer and verified handle |
| `POST /session` | `atp.session.store` | Start a session from a service auth token |
| `DELETE /session` | `atp.session.destroy` | End the session |

Two pages sit around sign-in: the login page and the page the callback finishes on, which runs `handleCallback()`. Give the package a callback for each and it serves them as `login` (guests only) and `login/complete`, returning whatever the callback returns. The package needs no view layer, so Inertia stays your dependency, not this package's:

```php
// AppServiceProvider::boot()
FrontendAuth::loginView(fn () => Inertia::render('auth/Login', [
    'providers' => FrontendAuth::providers(),
]));

FrontendAuth::completeView(fn () => Inertia::render('auth/Callback'));
```

A Blade view works the same way. Without a callback the package registers no page, and your own routes named by `login_route` and `complete_route` take over.

Users are found or created by DID like the `atp` guard does, and `handle_column` is kept current. Share the browser's settings with every page:

```php
'atproto' => FrontendAuth::browserConfig(),
```

A published config file replaces the package's `auth` block as a whole, so copy the `frontend` block into it when upgrading.

### Browser

The library ships in `resources/js`. Alias it in Vite and TypeScript:

```ts
// vite.config.ts
resolve: {
    alias: {
        '@socialdept/atp-appview': fileURLToPath(new URL('./vendor/socialdept/atp-appview/resources/js', import.meta.url)),
    },
},
```

```json
"paths": { "@socialdept/atp-appview": ["./vendor/socialdept/atp-appview/resources/js/index.ts"] }
```

Configure it once with the package's routes, as Wayfinder helpers or anything with a `url()` method, then keep the two sessions in step on every page:

```ts
import { configureAtproto, reconcile, syncSessions } from '@socialdept/atp-appview';
import * as oauth from '@/routes/atp/oauth';
import * as session from '@/routes/atp/session';

configureAtproto({
    routes: {
        prepare: oauth.prepare,
        clientAssertion: oauth.clientAssertion,
        flow: oauth.flow,
        account: oauth.account,
        startSession: session.store,
        endSession: session.destroy,
    },
    ...page.props.atproto,
});

await reconcile(page.props.auth.user);
```

| Call | What it does |
|---|---|
| `signIn({ provider } \| { handle } \| { did }, returnTo?)` | Leave for the authorization server |
| `signInPopup(did)` | Sign a known account back in through a popup, leaving the page as it is. Call it straight from a click |
| `handleCallback()` | On the complete page: exchange the code, check the account, store the session, start a server session |
| `pdsFetch(did, path, init)` | A request to the account's own PDS with DPoP, retried once after a rejected token |
| `withSession(action)` | Run an action, and if the session turns out dead, wait for the user to sign back in and run it once more |
| `reconcile(user)`, `syncSessions(options)` | Keep the server session in step with the browser's |
| `reconnectState()`, `onReconnectChange(listener)` | Whether to offer or ask for a sign-in, for a banner or a dialog |
| `signOut()` | Revoke the refresh token, forget the key and tokens, end the server session |

### Keeping sessions in step

The browser session is the source of truth. The server session can be rebuilt from it at any time, so the server never logs anyone out when tokens die.

- **Refresh** runs under a per-account Web Lock and re-reads storage inside it. Refresh tokens are single use, so another tab may have refreshed first.
- **Only a dead grant ends a session**: `invalid_grant`, `invalid_client`, `unauthorized_client`, a missing key, or a token the PDS rejects twice. Network failures, server errors and unknown answers leave it alone.
- **A dead session keeps its account** without tokens, so its handle is at hand to sign back in, and every change is announced to the other tabs.
- **A server session that ended** is rebuilt from the browser's while its access token still works. Otherwise the page offers to reconnect.
- **An action that hits a dead session** is not lost. `withSession()` raises a dialog, the user signs back in through a popup, and the action runs again. It only retries a request that never took effect.
- **Signing back in** prepares with the account's DID, and the browser refuses a sign-in that lands on another account.

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
$viewer->client();         // Atp::as($did), or null when the server holds no session
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

On a Tap with resync routes, such as the [socialde.pt/tap](https://tangled.org/socialde.pt/tap) fork, `resyncRepo($did)` backfills a tracked repo again while keeping what Tap already sent, so only records it never delivered go out. That is how a collection added to Tap's filters reaches repos it already follows, since `addRepos()` ignores them. `resyncAll()` does the same for every repo, and `supportsResync()` says whether the Tap has the routes. On a Tap without them, both resync methods throw `TapFeatureUnavailableException`.

[Tab](https://tangled.org/pds.dad/tab), a Tap fork that adds multiple signal collections, speaks the same protocol and works unchanged.

### Backfilling a new collection

Adding a collection later is the awkward case. Tap ignores repos it already follows when they are added again, and its collection filters only filter what it sends, so the older records of a new collection never arrive from repos it already follows. Tracked backfills handle that, and report progress while they run.

List the collections once, for both the index and Tap:

```dotenv
ATP_APPVIEW_COLLECTIONS=app.example.post,app.example.like
```

```yaml
# Tap, in docker-compose.yml
TAP_COLLECTION_FILTERS: ${ATP_APPVIEW_COLLECTIONS}
```

Then schedule the worker:

```php
Schedule::command('appview:backfill:work')->everyMinute()->withoutOverlapping();
```

To add a collection, append it to `ATP_APPVIEW_COLLECTIONS`, restart Tap and `appview:consume`, and the worker does the rest: it finds every repo holding the collection, as `appview:discover` does, and has Tap backfill them.

- **On a Tap with resync routes**, such as the [socialde.pt/tap](https://tangled.org/socialde.pt/tap) fork, repos Tap follows are resynced, which keeps what Tap already sent, so only the new collection's records go out. Repos it does not follow are added.
- **On any other Tap**, repos are removed and added again. That works, but Tap re-sends every collection of every repo, and each repo's live events wait until its backfill runs.

A repo counts as delivered once its first backfilled record arrives, or straight away if the index already holds its records in the collection, so running a backfill again finishes promptly instead of waiting on records Tap already sent. The backfill finishes when every repo has delivered, or once Tap has gone `backfill.quiet_minutes` (10) without delivering and its outbox is empty. The repos left either had nothing in the collection or could not be fetched, and Tap's reason is kept on `atp_backfill_repos.error`. If nothing at all arrived, the backfill fails instead, since Tap most likely backfilled the repos before the collection was in its filters. Fix the filters and start it again.

The first time the worker runs it has no history, so collections that already hold records count as backfilled, and only empty ones are backfilled, which is what a fresh app wants.

```bash
php artisan appview:backfill app.example.like    # start one by hand
```

`Backfills::progress()` reports each running backfill, and those that ended in the last hour, for a status page or admin panel. `active()` returns only the running ones, so a page can tell people that some records may be missing for now. `BackfillStarted` and `BackfillFinished` events fire as they start and end.

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

### Validation

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

### Following published lexicons

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

### Stored values

- **Postgres** stores `record` as `jsonb`, so fields can be read and indexed without re-parsing each document.
- **NUL characters** in a record's keys and strings are stored as U+FFFD. Postgres cannot read a field from json holding an escaped NUL, nor store one as jsonb, so a single such record would otherwise break queries across the table or fail its batch forever. For those rare records the stored value no longer matches the record's CID.
- **MySQL and MariaDB** store the index's dates as `DATETIME`, not `TIMESTAMP`, which holds only 1970 to 2038 and converts through the session timezone.

### Indexes

Each index on `atp_records` serves a specific read:

| Index | Serves |
| --- | --- |
| `uri` (primary key) | Fetching one record, and the upsert's conflict check |
| `collection, sort_at, uri` | A collection newest first, and the cursor seeking straight to its page |
| `did, collection, sort_at, uri` | One repo's records in a collection, newest first, and purging a repo |
| `collection, indexed_at` | What arrived recently |
| `collection, sort_at, uri` where hidden | A collection's hidden records, newest first (Postgres and SQLite) |
| `collection, sort_at, uri` where tombstoned | A collection's tombstones, newest first (Postgres and SQLite) |

Hidden records and tombstones are a sliver of each collection. Through the main sort index, listing them walks the whole collection to find them: 406 ms for 19 hidden records among 229,000 documents. The partial indexes hold only that sliver, and the same list takes 0.1 ms. MySQL and MariaDB have no partial indexes, so there those lists stay slow.

On `atp_identities`, `reconciled_at` serves `appview:reconcile` picking the repos due a check. Postgres builds it nulls first, since the run starts with repos never checked.

A cursor adds a plain `sort_at` range next to the tie-breaking comparison on `uri`, so the index seeks to the cursor rather than scanning every row before it. On 220,000 documents, a page 50,000 records deep takes 0.2 ms to execute.

### Counts

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

### Idempotent writes

Tap delivers at least once, so every write can safely happen twice:

- A record is only overwritten by an event with a newer revision, or the same revision and a different CID. The same record sent again unchanged, as Tap does when it resyncs a repo, leaves the row alone, so `indexed_at` and `sort_at` keep their values. The revision is the repo commit's `rev`, a TID that the repository spec requires to increase within a repo. TIDs are 13 characters from an alphabet in ASCII order, so the `rev` column's binary collation compares them in time order. Every comparison is between versions of the same record, so it never crosses repos.
- A delete becomes a tombstone that keeps the delete's revision and clears the body. An older create replayed during a backfill cannot bring the record back, and queries skip tombstones unless you call `withTrashed()`.
- Several events for one record in a batch collapse to the last, which Tap's per-repo ordering makes the newest.

Each batch is written with one statement per 500 records, inside a single transaction.

Backfilled events all carry the repo's revision at the time Tap exported it, not the revision of the commit that last changed each record. Anything Tap delivers live afterwards has a newer revision, so the guard orders them correctly.

### Reconciliation

When Tap resyncs a repo after losing track of it, it sends only the records whose CID it has not seen, and never a delete. A record removed while Tap was not following the repo would stay indexed, and a resync that only found deletions sends nothing at all, so the index cannot tell one happened.

`appview:reconcile` compares each repo with its own PDS instead. It reads the repo's latest revision, lists every collection in `ingest.collections` that the repo has, and then:

- tombstones an indexed record the PDS no longer has
- indexes a record the PDS has but the index lacks, or holds at another CID

Every write carries the revision read before listing, so nothing indexed at a newer revision, such as a record created or deleted while the listing ran, is touched. A listing that fails, or a collection past `index.reconcile.max_records`, writes nothing, since a partial listing would read as deletes. Blocked repos and collections are left alone.

Schedule it and it works through the active repos, least recently checked first, `index.reconcile.batch` per run, checking each again after `index.reconcile.every_hours`:

```php
Schedule::command('appview:reconcile')->everyMinute()->withoutOverlapping();
```

Expect about two seconds a repo, nearly all of it waiting on the PDS. Each repo's last check is kept on `atp_identities`: `reconciled_at`, the `reconciled_rev` it compared against, and `reconcile_error` when the PDS could not be read.

```bash
php artisan appview:reconcile did:plc:abc -v   # one repo, listing every change
php artisan appview:reconcile --dry-run        # report without writing
```

### Account status

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

### Reaching the AppView through a PDS

In frontend mode the browser calls the AppView through its own PDS, naming it in the `atproto-proxy` header. The PDS resolves the AppView's DID to find where to send the request, then signs a service auth token for it. For a `did:web`, set the endpoint and the package serves the DID document:

```dotenv
ATP_SERVICE_AUTH_AUDIENCE="did:web:example.com#atproto_appview"
ATP_APPVIEW_SERVICE_ENDPOINT=https://example.com
```

```
GET /.well-known/did.json
→ {"id": "did:web:example.com", "service": [{"id": "#atproto_appview", "type": "AtprotoAppView", "serviceEndpoint": "https://example.com"}]}
```

```ts
await pdsFetch(did, '/xrpc/com.example.getThings', {
    headers: { 'atproto-proxy': 'did:web:example.com#atproto_appview' },
});
```

The endpoint must be reachable from the internet, since the PDS makes the call. Only `/.well-known/did.json` and the XRPC routes need to be.

### Writing records

**Frontend mode.** The browser writes to its own PDS, and Tap delivers the record within seconds. To show the author their record straight away, turn on the sync procedure and have the browser call it, through its PDS, right after writing:

```php
// config/atp-appview.php
'xrpc' => [
    'sync_record' => 'com.example.syncRecord',
],
```

```
POST /xrpc/com.example.syncRecord   {"uri": "at://did:plc:alice/com.example.thing/3abc"}
→ {"uri": "at://did:plc:alice/com.example.thing/3abc", "indexed": true}
```

It fetches the record from the caller's own PDS and indexes it, or tombstones it if it is gone, so deletes show up as quickly. It takes only the caller's own records, only in `ingest.collections`, and never the record body from the request. The revision is read before the record, so Tap's later delivery can only replace it. Records a block rule covers answer `indexed: false`.

**Backend mode.** Default procedures write one collection to the viewer's repo through the session the server holds, then index the record before answering:

```php
'xrpc' => [
    'procedures' => [
        'com.example.createThing' => ['collection' => 'com.example.thing', 'action' => 'create'],
        'com.example.putThing' => ['collection' => 'com.example.thing', 'action' => 'put'],
        'com.example.deleteThing' => ['collection' => 'com.example.thing', 'action' => 'delete'],
    ],
],
```

| Action | Input | Answers |
|---|---|---|
| `create` | `{record, rkey?}` | `{uri, cid}` |
| `put` | `{rkey, record, swapRecord?}` | `{uri, cid}` |
| `delete` | `{rkey, swapRecord?}` | nothing |

- The viewer is required, and must be one the server holds a session for. Others get `BackendSessionRequired` (403), telling them to write to their PDS.
- The record is given the collection's `$type`, and checked against the collection's lexicon when one is loaded, before anything reaches the PDS. The PDS is asked not to validate, since it refuses lexicons it does not know.
- The record is indexed with the commit's revision, so the author sees it at once and Tap's later delivery changes nothing.
- The PDS's own errors, such as `InvalidSwap`, come back as they were. Repos a block rule covers get `RepoBlocked` (403).
- A lexicon for the procedure is optional. If one exists, it must be a procedure.

### Middleware

Add `'web'` to `atp-appview.xrpc.middleware` so session viewers resolve in backend mode. A handler without a lexicon declares `type: 'query'` or `type: 'procedure'` on the attribute.

**CSRF.** The web group's CSRF check would refuse every procedure a PDS calls on a user's behalf, since those carry a service auth bearer token and no CSRF token. So XRPC routes take that check off and run their own: requests with a bearer token, or with no session cookie, pass, since nothing the browser attaches on its own is involved. A request riding on a session cookie still runs your application's own CSRF middleware, with its exceptions, and a failure answers `{"error": "CsrfTokenMismatch"}` with status 419. Browser code calling procedures with the session sends the token as usual, as Inertia and axios do.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
