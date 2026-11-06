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
- **Retention:** opt in to keeping the index small: LZ4 compression for stored records on Postgres, pruning old tombstones, collections kept only while recent, and cold records whose bodies are fetched again when read.
- **XRPC:** serve queries and procedures generated from lexicons, with custom handlers where the default is not enough.
- **Auth:** one guard that works whether OAuth tokens live on the server or in the browser, with service proxying in both cases.

It builds on [atp-client](https://github.com/socialdept/atp-client) for OAuth, service auth and forwarding, and on [atp-schema](https://github.com/socialdept/atp-schema) for lexicons.

## Installation

```bash
composer require socialdept/atp-appview
php artisan vendor:publish --tag=atp-appview-config
```

The config is split: `config/atp-appview.php` holds what is global (auth, ingest, the index, the service identity, XRPC), and `config/atp-appview/` holds a file per area (`fields.php`, `views.php`, `profiles.php`, `images.php`, `lexicons.php`, `backfill.php`, `blocklist.php`, `retention.php`). Laravel loads each as a key of its own, `atp-appview.fields` and so on. Every section keeps the package's defaults for settings it does not mention, so a setting added in a later release reaches a config published before it.

Then run `php artisan migrate`. Postgres, MySQL, MariaDB and SQLite are supported. On MySQL and MariaDB, set the connection's session timezone to UTC with `'timezone' => '+00:00'` in its entry in `config/database.php`. The package writes times in UTC, and MySQL converts TIMESTAMP columns through the session timezone, so in any other zone a time near a daylight saving change shifts by an hour.

## Documentation

The [Features](docs/getting-started/features.md) doc covers what an app turns on beyond the core. The other docs are grouped by area, and each group has an index of its own:

- **[Getting started](docs/getting-started/README.md)**: [Features](docs/getting-started/features.md), [Auth](docs/getting-started/auth.md)
- **[Ingest and index](docs/data/README.md)**: [Ingest](docs/data/ingest.md), [Blocklist](docs/data/blocklist.md), [Index](docs/data/indexing.md), [Retention](docs/data/retention.md)
- **[Serving](docs/serving/README.md)**: [XRPC](docs/serving/xrpc.md), [Hydrated views](docs/serving/views.md), [Feeds](docs/serving/feeds.md), [Search](docs/serving/search.md)
- **[Social](docs/social/README.md)**: [Social graph](docs/social/social-graph.md), [Notifications](docs/social/notifications.md), [Realtime](docs/social/realtime.md)
- **[Trust](docs/trust/README.md)**: [Moderation](docs/trust/moderation.md), [Standing](docs/trust/standing.md), [Claims](docs/trust/claims.md)
- **[Spaces](docs/spaces.md)**: A copy of the permissioned spaces the app follows, synced from each member's PDS, kept apart from the public index, and read only by viewers the space lets in.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
