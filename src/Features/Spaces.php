<?php

namespace SocialDept\AtpAppview\Features;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use LogicException;
use SocialDept\AtpAppview\Console\SpacesSyncCommand;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Spaces\Listeners\DropDeletedSpace;
use SocialDept\AtpAppview\Spaces\Listeners\SyncNotifiedWrite;
use SocialDept\AtpSpaces\Enums\SpaceNsid;
use SocialDept\AtpSpaces\Events\SpaceDeletedNotified;
use SocialDept\AtpSpaces\Events\SpaceWriteNotified;
use SocialDept\AtpSpaces\Http\Controllers\SyncerController;

/**
 * A copy of the spaces the app follows: permissioned records synced from
 * each member's own PDS with atp-spaces, kept in atp_space_records apart
 * from the public index. Adds atp_spaces, atp_space_repos and
 * atp_space_records, appview:spaces:sync, and listeners for the
 * notifications a space's authority sends, whose routes atp-spaces serves.
 *
 * Off, nothing is synced, no notification routes are served for it, and
 * the public index is unchanged.
 */
class Spaces implements Feature
{
    public function requires(): array
    {
        return [];
    }

    public function register(AppView $appview): void
    {
        $appview
            ->config('spaces')
            ->migrations(__DIR__.'/../../database/migrations/spaces')
            ->commands([SpacesSyncCommand::class])
            ->listen(SpaceWriteNotified::class, SyncNotifiedWrite::class)
            ->listen(SpaceDeletedNotified::class, DropDeletedSpace::class)
            ->schedule(fn (Schedule $schedule) => $schedule->command('appview:spaces:sync --queue')->everyTenMinutes());

        // Authorities deliver notifications to the routes atp-spaces serves
        // while its syncer is on. It registers them as it boots, which may
        // be before or after this, so they are added here when missing.
        config()->set('atp-spaces.syncer.enabled', true);
        $appview->routes(fn (Router $router) => self::syncerRoutes($router));
    }

    /**
     * atp-spaces' notifyWrite and notifySpaceDeleted routes, as it would
     * register them, unless it has.
     */
    public static function syncerRoutes(Router $router): void
    {
        $config = (array) config('atp-spaces.syncer', []);
        $prefix = rtrim((string) ($config['prefix'] ?? '/xrpc'), '/');

        // Names given after a route is added are found only once refreshed.
        $router->getRoutes()->refreshNameLookups();

        foreach (['write' => SpaceNsid::NotifyWrite, 'spaceDeleted' => SpaceNsid::NotifySpaceDeleted] as $action => $nsid) {
            if ($router->has('atp-spaces.'.$nsid->value)) {
                continue;
            }

            $router->post($prefix.'/'.$nsid->value, [SyncerController::class, $action])
                ->middleware(array_merge(['atp.service-auth:'.$nsid->value], (array) ($config['middleware'] ?? [])))
                ->name('atp-spaces.'.$nsid->value);
        }
    }

    public static function on(): bool
    {
        return Features::enabled(self::class);
    }

    /**
     * @throws LogicException when the feature is off
     */
    public static function ensureOn(): void
    {
        if (! self::on()) {
            throw new LogicException('Spaces needs the spaces feature: add Features::spaces() to atp-appview.features.');
        }
    }
}
