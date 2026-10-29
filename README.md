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
php artisan vendor:publish --tag=atp-appview-config
```

The config is split: `config/atp-appview.php` holds what is global (auth, ingest, the index, the service identity, XRPC), and `config/atp-appview/` holds a file per area (`fields.php`, `views.php`, `profiles.php`, `images.php`, `lexicons.php`, `backfill.php`, `blocklist.php`). Laravel loads each as a key of its own, `atp-appview.fields` and so on. Every section keeps the package's defaults for settings it does not mention, so a setting added in a later release reaches a config published before it.

## Documentation

The [Features](docs/features.md) doc covers what an app turns on beyond the core. Each other doc covers one area:

- **[Features](docs/features.md)**: Turning parts of the package on and off, scheduling, and writing a feature of your own.
- **[Auth](docs/auth.md)**: Frontend and backend mode, sign-in from the browser, and the atp guard.
- **[Ingest](docs/ingest.md)**: Tap and Tab delivery, Jetstream, handlers, hooks, repairs, failed deliveries, backfills, discovery and companion collections.
- **[Moderation](docs/moderation.md)**: Labels from labelers through Tack or their own streams, policies, self-labels and account actions.
- **[Blocklist](docs/blocklist.md)**: Keeping repos, collections and hosts out of the index.
- **[Index](docs/indexing.md)**: How records are stored, sorted, validated and reconciled, account status, and index stats.
- **[XRPC](docs/xrpc.md)**: Default queries and procedures, custom handlers, calling methods, and writing records.
- **[Hydrated views](docs/views.md)**: Views with authors, embeds, counts and viewer state, reply trees, fields kept in the index, profiles and images.
- **[Social graph](docs/social-graph.md)**: Follows and blocks from the collections you name, private mutes, and where they apply.
- **[Notifications](docs/notifications.md)**: Reasons, notifiers, grouping, seen state, preferences and delivery.
- **[Search](docs/search.md)**: Records by their words, Latest and Top, and the accounts the index holds.
- **[Feeds](docs/feeds.md)**: Custom feeds in the shapes of Bluesky's feed generators, under Bluesky's names or your own.
- **[Standing](docs/standing.md)**: Follower and link counts, proven verification by pluggable verifiers, and weighted scores.
- **[Realtime](docs/realtime.md)**: Pings over Laravel broadcasting when records and notifications change, and the record events behind them.
- **[Spaces](docs/spaces.md)**: A copy of the permissioned spaces the app follows, synced from each member's PDS and kept apart from the public index.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
