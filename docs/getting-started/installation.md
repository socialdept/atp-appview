# Installation

Part of the [atp-appview docs](../introduction.md#documentation). Installing the package, its config and the database.

## Installing

```bash
composer require socialdept/atp-appview
php artisan vendor:publish --tag=atp-appview-config
```

## Configuration

The config is split: `config/atp-appview.php` holds what is global (auth, ingest, the index, the service identity, XRPC), and `config/atp-appview/` holds a file per area (`fields.php`, `views.php`, `profiles.php`, `images.php`, `lexicons.php`, `backfill.php`, `blocklist.php`, `retention.php`). Laravel loads each as a key of its own, `atp-appview.fields` and so on. Every section keeps the package's defaults for settings it does not mention, so a setting added in a later release reaches a config published before it.

## Database

Run `php artisan migrate`. Postgres, MySQL, MariaDB and SQLite are supported. On MySQL and MariaDB, set the connection's session timezone to UTC with `'timezone' => '+00:00'` in its entry in `config/database.php`. The package writes times in UTC, and MySQL converts TIMESTAMP columns through the session timezone, so in any other zone a time near a daylight saving change shifts by an hour.
