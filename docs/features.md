# Features

Part of the [atp-appview docs](../README.md#documentation). Turning parts of the package on and off, scheduling, and writing a feature of your own.

Beyond the core (ingest, the index, XRPC, auth, views and profiles), an AppView turns on features, listed the way Laravel Fortify lists its own:

```php
// config/atp-appview.php
use SocialDept\AtpAppview\Features;

'features' => [
    Features::moderation(),
    Features::notifications(['latest' => 5]),
    Features::graph(),
    Acme\Search\MeilisearchFeature::class,
],
```

A helper's options go over that feature's config section, so `Features::notifications(['latest' => 5])` is the same as setting `latest` in `config/atp-appview/notifications.php`. `Features::enabled(Features::graph())` says whether one is on.

Push is a fourth, `Features::push()`, off unless listed. See [Push](push.md).

All three ship enabled, and leaving one out turns it off: its tables are not created, its routes, commands and XRPC methods are not registered, and nothing is kept for it as records are indexed. Left out, the graph answers as though nobody follows, blocks or mutes anyone. Left out, moderation applies no labels and nobody counts as banned or under review, and the `Moderation` and `Labels` view parts are neutral. The notifications, notification preferences, mutes, label policy and account action APIs refuse when their feature is off, naming the feature to add.

## Scheduling

Features never schedule work on their own. To run what the enabled features recommend, opt in with one line, or schedule their commands yourself:

```php
// routes/console.php
use SocialDept\AtpAppview\Features;

Features::schedule();
```

## Writing a feature

A feature is a class implementing `Feature`. It names the features it needs, and registers what it adds through `AppView`:

```php
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Features\Feature;

class MeilisearchFeature implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            ->config('search', ['index' => 'records'])            // atp-appview.search, with defaults
            ->migrations(__DIR__.'/../database/migrations')
            ->handler(IndexForSearch::class)                       // a batch handler after the package's own
            ->xrpc('com.acme.search.query', SearchHandler::class)
            ->commands([ReindexCommand::class])
            ->schedule(fn (Schedule $schedule) => $schedule->command('search:reindex')->daily());
    }
}
```

It can also add fields kept in the index (`field()`), work inside the indexer's transaction on the rows it wrote (`afterWrite()`), routes (`routes()`), and accounts a viewer should not see (`readFilter()`, applied by `visibleTo()`, the built-in XRPC lists and notifications). A feature left out of the list registers nothing, so its migrations, routes and commands stay out of the app. One that needs a feature left out fails at boot, naming it.
