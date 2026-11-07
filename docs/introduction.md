# Introduction

**ATP AppView** turns a Laravel application into an AT Protocol AppView:

- **Ingest:** sync any set of collections from the network through Tap or Tab, in batches, without flooding the app during backfill.
- **Index:** keep every ingested record in the application's own database, queryable through Eloquent.
- **Retention:** opt in to keeping the index small: LZ4 compression for stored records on Postgres, pruning old tombstones, collections kept only while recent, and cold records whose bodies are fetched again when read.
- **XRPC:** serve queries and procedures generated from lexicons, with custom handlers where the default is not enough.
- **Auth:** one guard that works whether OAuth tokens live on the server or in the browser, with service proxying in both cases.

It builds on [atp-client](https://github.com/socialdept/atp-client) for OAuth, service auth and forwarding, and on [atp-schema](https://github.com/socialdept/atp-schema) for lexicons.

## A first look

Name the collections to sync, and every record in them arrives in your database as it is written on the network:

```php
// config/atp-appview.php
'ingest' => [
    'collections' => ['app.example.post', 'app.example.comment', 'app.example.like'],
],
```

Records are Eloquent models, so the index is queried like any other table:

```php
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;

Record::collection('app.example.post')->by($did)->latest('sort_at')->get();

$record->record;              // the record body as an array
$record->identity->handle;    // the author, when the index has seen their identity
```

A query that only lists one collection needs no code, just a lexicon and a line of config. Anything more is a class:

```php
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

## Documentation

Start with [Installation](getting-started/installation.md), then [Features](getting-started/features.md) for what an app turns on beyond the core. The docs are grouped by area, and each group has an index of its own:

- **[Getting started](getting-started/README.md)**: [Installation](getting-started/installation.md), [Features](getting-started/features.md), [Auth](getting-started/auth.md)
- **[Ingest and index](data/README.md)**: [Ingest](data/ingest.md), [Blocklist](data/blocklist.md), [Index](data/indexing.md), [Retention](data/retention.md)
- **[Serving](serving/README.md)**: [XRPC](serving/xrpc.md), [Hydrated views](serving/views.md), [Feeds](serving/feeds.md), [Search](serving/search.md)
- **[Social](social/README.md)**: [Social graph](social/social-graph.md), [Notifications](social/notifications.md), [Realtime](social/realtime.md)
- **[Trust](trust/README.md)**: [Moderation](trust/moderation.md), [Standing](trust/standing.md), [Claims](trust/claims.md)
- **[Spaces](spaces.md)**: A copy of the permissioned spaces the app follows, synced from each member's PDS, kept apart from the public index, and read only by viewers the space lets in.
