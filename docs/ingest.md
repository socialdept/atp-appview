# Ingest

Part of the [atp-appview docs](../README.md#documentation). Tap and Tab delivery, handlers, hooks, repairs, failed deliveries, backfills, discovery and companion collections.

[Tap](https://github.com/bluesky-social/indigo/tree/main/cmd/tap) does the network work: crawling, fetching repos, verifying commits and ordering events per repo. It delivers what it finds to your application in batches, and each batch goes to your handlers. The application only writes what arrives.

By default Tap delivers by webhook, so there is no worker to keep running. This needs batched webhooks, which [Tab](https://tangled.org/pds.dad/tab), a fork of Tap, adds, with its image at `fatfingers23/tab`. Its settings use the `TAB_` prefix in place of `TAP_`. Stock Tap sends one request per event, which buries a PHP application during a backfill. With stock Tap, [run a worker](#running-a-worker-instead) instead.

```dotenv
ATP_APPVIEW_TAP_ADMIN_URL=http://localhost:2480
ATP_APPVIEW_TAP_PASSWORD=a-long-random-secret
```

```yaml
# Tap
TAB_WEBHOOK_URL: https://your.app/atp-appview/tap
TAB_WEBHOOK_BATCH_SIZE: 200
TAB_ADMIN_PASSWORD: ${ATP_APPVIEW_TAP_PASSWORD}
```

## How a batch is delivered

Tap posts each batch as `{"events": [...]}` and signs it with its admin password, which the route checks against `ingest.password`, so it refuses everything until one is set.

1. The events go through the blocklist, then every handler, in order.
2. A failing batch is retried `max_attempts` times (3) with backoff.
3. The request succeeds only once every handler has committed the batch. Anything else gets an error response, and Tap keeps the batch and sends it again.

Tap sends one batch at a time unless `TAB_WEBHOOK_BATCH_PARALLELISM` says otherwise, so the application sets the pace, and events wait in Tap while the application is down. Delivery is at least once and Tap keeps events for one repo in order, so handlers must apply a batch idempotently.

Each request reads the config fresh, so there is nothing to restart after a deploy. The path is `ingest.webhook.path`, and `ingest.webhook.middleware` adds route middleware. A config published before the webhook existed still gets the `webhook` defaults, since each section keeps the package's defaults for settings it does not mention.

## Running a worker instead

`php artisan appview:consume` reads Tap's acknowledged websocket channel instead, which stock Tap speaks too. Turn the webhook off and point the worker at the channel:

```dotenv
ATP_APPVIEW_TAP_WEBHOOK=false
ATP_APPVIEW_TAP_URL=ws://localhost:2480/channel
```

Tap stops sending once its outbox of unacknowledged events is full, and the worker acknowledges a batch only after every handler has committed it. While a batch is being handled the worker reads nothing, so the database sets the pace.

1. Events are buffered until `batch_size` arrive (200) or `flush_ms` passes (500).
2. Every handler receives the batch, in order.
3. Each event is acknowledged.
4. A failing batch is retried `max_attempts` times (3) with backoff. After that the worker reconnects without acknowledging it, and Tap redelivers it.

Running several workers is fine: Tap shards events across connected clients.

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

The worker also restarts itself when its config changes. Every minute it compares `.env`, the config cache and the files in `config/` with how they were when it started, and exits if any changed, so adding a collection or a handler takes effect without a manual restart. That only sees files in place: a zero-downtime deploy into a new release directory leaves the worker looking at the old one, so keep `appview:restart` in the deploy.

Exiting is always lossless. The batch in progress finishes first, and anything not yet acknowledged is redelivered by Tap when the worker comes back.

## Handlers

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

Each attempt at a batch runs in one transaction on the index connection, so an attempt that fails leaves none of its writes behind, whichever handler failed. What a handler does outside the database, such as calling a service, cannot be undone, which is one more reason handlers must take a batch twice without harm.

## Ingest hooks

A hook looks at records before they are indexed and rejects those the app does not want: spam, or records breaking an app rule.

```php
use SocialDept\AtpAppview\Ingest\Contracts\IngestHook;

class RejectSpam implements IngestHook
{
    public function reject(array $records): array
    {
        $rejected = [];

        foreach ($records as $event) {
            if (str_contains($event->record['text'] ?? '', 'buy followers')) {
                $rejected[$event->uri()] = 'spam';
            }
        }

        return $rejected;   // uri => reason
    }
}
```

List hooks in `atp-appview.ingest.hooks`. They run in order on the whole batch, after the blocklist and the repairs. Only creates and updates are offered, so a delete always goes through. A hook never changes a record. Rewriting one is what repairs are for. Rejections are tallied by hook, collection and reason, with the latest record, in `atp_ingest_rejections`, once the batch has gone through, and `Rejections::all()` reads them.

A hook only sees records as they arrive. To apply a new or changed hook to records already indexed, run `php artisan appview:hooks:apply [collection]`. Each rejected record is removed as a delete at its stored rev, so its values, notifications and labels go with it, and the rejection is tallied. `--dry-run` counts what would go without removing anything.

## Ingest repairs

A repair rewrites a record the app knows how to fix before it is indexed, such as a date in a format the lexicon does not allow. Without one, such a record fails and is parked as a failed delivery, or is indexed with a value queries cannot use.

```php
use SocialDept\AtpAppview\Ingest\Contracts\IngestRepair;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;

class SlashDates implements IngestRepair
{
    public function repair(RecordEvent $record): ?array
    {
        $date = $record->record['createdAt'] ?? null;

        if (! is_string($date) || ! preg_match('#^(\d{4})/(\d{2})/(\d{2})$#', $date, $parts)) {
            return null;   // leave it as it is
        }

        return [...$record->record, 'createdAt' => "{$parts[1]}-{$parts[2]}-{$parts[3]}T00:00:00Z"];
    }
}
```

Repairs are off until listed in `atp-appview.ingest.repairs`. They run in order on every create and update, each seeing the record as the one before left it, after the blocklist and before the hooks, so hooks judge the repaired record.

A repaired record no longer matches its CID, so the index says so. `atp_records.repair` holds the repairs that changed it and the record as it is in the repo, and is null for every other record. The author's next version replaces both. Repairs are tallied by repair and collection in `atp_ingest_repairs`, and `Repairs::all()` reads them.

Fixing records in code rather than by hand means one repair covers every record with the same problem, the ones already parked included: add the repair, then retry the failed deliveries.

## Ingest pipes

The blocklist, the repairs and the hooks are the default `atp-appview.ingest.pipes`: a batch passes through them as through any Laravel pipeline, then the handlers take it. An app can add its own pipe, a class with `handle(Batch $batch, Closure $next)`. Code after `$next()` runs once the handlers have taken the batch.

## Failed deliveries

Without this, one record no handler can take would hold up everything behind it: the worker reconnects without acknowledging the batch and Tap sends it again, or the webhook fails and Tap retries it, forever.

So a batch that still fails after `max_attempts` is split in halves until the events failing on their own are found. Those are parked in `atp_failed_deliveries`, with the event and the error, and the rest of the batch goes through. When every event fails on its own, the cause is more likely the database or a service than the records, so the batch goes back to Tap as before, and an event is parked only once it has failed in `failed_deliveries.max_deliveries` (3) separate deliveries.

```bash
php artisan appview:failed-deliveries                          # count by collection and error
php artisan appview:failed-deliveries --retry                  # run them through ingest again, once fixed
php artisan appview:failed-deliveries --reindex                # fetch each record from its PDS as it is now
php artisan appview:failed-deliveries --forget --collection=app.example.post
php artisan appview:failed-deliveries --prune                  # drop those past keep_days and max
```

How long to keep them depends on how many arrive, so it is configured in `config/atp-appview/failed_deliveries.php`: `keep_days` (90, null to keep them until forgotten) and `max`, a cap with the oldest dropped first. Schedule the prune:

```php
Schedule::command('appview:failed-deliveries --prune')->daily();
```

`FailedDeliveries::retry($delivery)` runs one through ingest as configured now, and drops it once it goes through. Retry once the cause is in the app: a bug fixed, a repair added.

`FailedDeliveries::reindex($delivery)` fetches the record from its author's PDS instead, and runs that through ingest. Reindex once the cause was in the record: the author fixed it or deleted it, or Tap delivered it damaged. A record that is gone is tombstoned. Identity events have no record to fetch, so they can only be retried.

## Backfill status

Tap marks each record event: `live` false while it backfills a repo, true for commits arriving in real time. It exposes no count of repos still waiting, so `IngestStats` reads the stream instead. A backfill is running while backfill events keep arriving.

```php
use SocialDept\AtpAppview\Ingest\IngestStats;

$stats = app(IngestStats::class);

$stats->backfilling();      // a backfill event arrived in the last minute
$stats->lastBackfillAt();   // when the last one arrived
$stats->lastLiveAt();
$stats->perMinute();        // ['backfill' => …, 'live' => …, 'identity' => …] over five minutes
```

That covers every backfill, including later ones such as repos `appview:discover` adds. Events are counted as Tap delivered them, before the blocklist, so a backfill of blocked repos still counts. Counts reach the cache in per-minute buckets that several requests or workers can share: once per webhook request, and at most every five seconds from `appview:consume`. An application Tap cannot reach reads as not backfilling, so pair this with Tap's outbox size, which stays above zero while events wait to be delivered.

`TapAdmin::stats('resync-buffer')` is not a queue of repos. It counts live events Tap holds back for repos that are mid-backfill.

## Discovering repos

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

## Companion collections

Some collections describe a repo rather than being a reason to follow it. Profiles are the usual case: nearly every account has one, so discovering repos by `app.bsky.actor.profile` would have Tap follow most of the network. List those as companion collections instead:

```dotenv
ATP_APPVIEW_COLLECTIONS=app.example.post,app.example.like
ATP_APPVIEW_COMPANION_COLLECTIONS=app.bsky.actor.profile
```

A companion collection is indexed only for repos already followed. `appview:discover` never looks for it and refuses one passed by name. Adding one backfills the active repos the index already knows instead of finding repos, and never adds a repo to Tap. On a Tap with resync routes it asks once, through `/resync-all`, and a repo the index knows but Tap does not follow simply never delivers. Reconciliation, status counts and record sync cover both lists. A collection in both lists counts as followed.

## Tap admin

`TapAdmin` wraps Tap's admin API: `addRepos()` to backfill and follow specific repos, `removeRepos()`, `info($did)`, `stats('repo-count')` and `healthy()`.

On a Tap with resync routes, such as [Tab](https://tangled.org/pds.dad/tab), `resyncRepo($did)` backfills a tracked repo again while keeping what Tap already sent, so only records it never delivered go out. That is how a collection added to Tap's filters reaches repos it already follows, since `addRepos()` ignores them. `resyncAll()` does the same for every repo, and `supportsResync()` says whether the Tap has the routes. On a Tap without them, both resync methods throw `TapFeatureUnavailableException`.

[Tab](https://tangled.org/pds.dad/tab) also adds multiple signal collections, and speaks the same protocol as Tap otherwise.

## Backfilling a new collection

Adding a collection later is the awkward case. Tap ignores repos it already follows when they are added again, and its collection filters only filter what it sends, so the older records of a new collection never arrive from repos it already follows. Tracked backfills handle that, and report progress while they run.

List the collections once, for both the index and Tap, whose filters are both lists:

```dotenv
ATP_APPVIEW_COLLECTIONS=app.example.post,app.example.like
ATP_APPVIEW_COMPANION_COLLECTIONS=app.bsky.actor.profile
```

```yaml
# Tap, in docker-compose.yml
TAB_COLLECTION_FILTERS: ${ATP_APPVIEW_COLLECTIONS},${ATP_APPVIEW_COMPANION_COLLECTIONS}
```

Then schedule the worker:

```php
Schedule::command('appview:backfill:work')->everyMinute()->withoutOverlapping();
```

To add a collection, append it to `ATP_APPVIEW_COLLECTIONS`, restart Tap (and `appview:consume`, if you run it), and `appview:backfill:work` does the rest: it finds every repo holding the collection, as `appview:discover` does, and has Tap backfill them. A companion collection backfills the repos already followed instead.

- **On a Tap with resync routes**, such as [Tab](https://tangled.org/pds.dad/tab), repos Tap follows are resynced, which keeps what Tap already sent, so only the new collection's records go out. Repos it does not follow are added.
- **On any other Tap**, repos are removed and added again. That works, but Tap re-sends every collection of every repo, and each repo's live events wait until its backfill runs.

Handing repos over survives a busy Tap: each request is retried three times, and a repo Tap still does not take keeps the error, counts as unreachable, and leaves the rest of the backfill running. Only a backfill Tap takes none of fails. Starting again after a failed attempt skips the repos that attempt already handed over, and the failed attempt leaves the dashboard once its retry starts.

A repo counts as delivered once its first backfilled record arrives, or straight away if the index already holds its records in the collection, so running a backfill again finishes promptly instead of waiting on records Tap already sent. The backfill finishes once Tap has stopped sending: when every repo has delivered and no record has arrived for `backfill.settle_seconds` (60), or, with repos that never delivered, after `backfill.quiet_minutes` (10) without a record, and in both cases only with Tap's outbox empty. A repo counts as delivered at its first record, so the settle period waits out the rest. The repos left either had nothing in the collection or could not be fetched, and Tap's reason is kept on `atp_backfill_repos.error`. If nothing at all arrived, the backfill fails instead, since Tap most likely backfilled the repos before the collection was in its filters. Fix the filters and start it again.

The first time the worker runs it has no history, so collections that already hold records count as backfilled, and only empty ones are backfilled, which is what a fresh app wants. The exception is a Tap still backfilling on that first run, as on a fresh install, where Tap starts on its signal collection's repos before the scheduler does. With resync routes, those collections are backfilled again and tracked, which sends nothing for repos Tap already finished.

```bash
php artisan appview:backfill app.example.like    # start one by hand
```

`Backfills::progress()` reports each running backfill, and those that ended in the last hour, for a status page or admin panel. `active()` returns only the running ones, so a page can tell people that some records may be missing for now. `summary()` adds the running ones up for that notice: repos to read, read so far, the percent, and the longest estimate left, or null when nothing is running. `BackfillStarted` and `BackfillFinished` events fire as they start and end.
