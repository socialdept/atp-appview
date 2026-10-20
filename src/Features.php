<?php

namespace SocialDept\AtpAppview;

use Illuminate\Console\Scheduling\Schedule;
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Features\Graph;
use SocialDept\AtpAppview\Features\Moderation;
use SocialDept\AtpAppview\Features\Notifications;

/**
 * The features an app turns on, listed in atp-appview.features as Laravel
 * Fortify lists its own:
 *
 *     'features' => [
 *         Features::moderation(),
 *         Features::notifications(['latest' => 5]),
 *         Features::graph(),
 *         Acme\Search\MeilisearchFeature::class,
 *     ],
 *
 * Each helper returns its feature's class name, so the list stays a plain
 * array that config:cache can store. Options are kept under their own key,
 * atp-appview-features, since Laravel replaces the whole atp-appview key
 * with the file's array once the file has run.
 */
class Features
{
    public const OPTIONS = 'atp-appview-features';

    /**
     * Labels and labelers, Tack, policies, self-labels and account actions.
     *
     * @param  array<string, mixed>  $options  Over atp-appview.moderation
     */
    public static function moderation(array $options = []): string
    {
        return static::feature(Moderation::class, $options);
    }

    /**
     * Notifications: reasons, notifiers, grouping, preferences and the XRPC
     * methods.
     *
     * @param  array<string, mixed>  $options  Over atp-appview.notifications
     */
    public static function notifications(array $options = []): string
    {
        return static::feature(Notifications::class, $options);
    }

    /**
     * The social graph: follows, blocks and mutes.
     *
     * @param  array<string, mixed>  $options  Over atp-appview.graph
     */
    public static function graph(array $options = []): string
    {
        return static::feature(Graph::class, $options);
    }

    /**
     * Any feature by class name, with options for its config section.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $options
     */
    public static function feature(string $class, array $options = []): string
    {
        if ($options !== []) {
            config([static::OPTIONS.'.'.$class => $options]);
        }

        return $class;
    }

    /**
     * The features turned on, by class name.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_values(array_unique((array) config('atp-appview.features', [])));
    }

    public static function enabled(string $feature): bool
    {
        return in_array($feature, static::all(), true);
    }

    /**
     * Register what every enabled feature recommends scheduling. Call it
     * from routes/console.php to opt in, or schedule the commands yourself.
     */
    public static function schedule(?Schedule $schedule = null): void
    {
        $schedule ??= app(Schedule::class);

        foreach (app(AppView::class)->scheduleCallbacks() as $callback) {
            $callback($schedule);
        }
    }
}
