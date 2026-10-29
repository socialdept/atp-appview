<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Spaces\SpaceSync;
use SocialDept\AtpAppview\Spaces\SyncSpace;
use SocialDept\AtpSpaces\SpaceRef;
use Throwable;

class SpacesSyncCommand extends Command
{
    protected $signature = 'appview:spaces:sync
                            {spaces?* : Space URIs to sync, every followed space when none}
                            {--queue : Queue a sync of each space instead of running it here}';

    protected $description = 'Catch followed spaces up and renew their notification registrations';

    public function handle(SpaceSync $sync): int
    {
        $named = (array) $this->argument('spaces');
        $spaces = [];

        foreach ($named as $uri) {
            $space = SpaceRef::tryParse((string) $uri);

            if ($space === null) {
                $this->components->error("{$uri} is not a space URI.");

                return self::FAILURE;
            }

            $spaces[] = $space;
        }

        if ($named === []) {
            $spaces = $sync->followed();
        }

        if ($spaces === []) {
            $this->components->info('No spaces are followed.');

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            foreach ($spaces as $space) {
                SyncSpace::dispatch((string) $space);
            }

            $this->components->info('Queued '.count($spaces).' space syncs.');

            return self::SUCCESS;
        }

        $synced = 0;
        $waiting = 0;
        $failed = 0;

        foreach ($spaces as $space) {
            try {
                $sync->sync($space) ? $synced++ : $waiting++;
            } catch (Throwable $e) {
                $failed++;
                $this->components->warn("{$space}: {$e->getMessage()}");
            }
        }

        $this->components->info("Synced {$synced} spaces, {$waiting} waiting for a credential or not followed, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
