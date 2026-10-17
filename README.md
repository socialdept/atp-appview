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

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
