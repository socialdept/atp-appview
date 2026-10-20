<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Notifications\NotificationIndexer;
use SocialDept\AtpAppview\Notifications\Notifications;

class NotificationsBuildCommand extends Command
{
    protected $signature = 'appview:notifications:build
                            {collection? : Only this collection}';

    protected $description = 'Add notifications for records indexed before their reasons were configured';

    public function handle(NotificationIndexer $indexer): int
    {
        if (Notifications::reasons() === []) {
            $this->components->warn('No reasons are configured in atp-appview.notifications.reasons.');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $added = $indexer->build($this->argument('collection'), function (int $read) {
            if ($read % 10000 === 0) {
                $this->components->twoColumnDetail('Records read', number_format($read));
            }
        });

        $this->components->info(sprintf('Added %s notifications in %.1f s.', number_format($added), microtime(true) - $started));

        return self::SUCCESS;
    }
}
