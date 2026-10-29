<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SocialDept\AtpAppview\Notifications\Contracts\Notifier;
use SocialDept\AtpAppview\Notifications\NotificationIndexer;
use SocialDept\AtpAppview\Notifications\Notifications;
use Throwable;

class NotificationsBuildCommand extends Command
{
    protected $signature = 'appview:notifications:build
                            {collection? : Only this collection}
                            {--notifier=* : Run these Notifier classes over indexed records instead of the configured reasons}
                            {--since= : Only records indexed at or after this time}
                            {--until= : Only records indexed before this time}
                            {--announce : Dispatch NotificationCreated for what a notifier adds}';

    protected $description = 'Add notifications for records indexed before their reasons or notifiers were configured';

    public function handle(NotificationIndexer $indexer): int
    {
        try {
            $since = $this->time('since');
            $until = $this->time('until');
        } catch (Throwable) {
            $this->components->error('--since and --until take a date and time, such as 2026-10-01 or 2026-10-01T12:00:00Z.');

            return self::INVALID;
        }

        $notifiers = (array) $this->option('notifier');

        if ($this->option('announce') && $notifiers === []) {
            $this->components->error('--announce needs --notifier. Configured reasons are built without announcing them.');

            return self::INVALID;
        }

        $progress = function (int $read) {
            if ($read % 10000 === 0) {
                $this->components->twoColumnDetail('Records read', number_format($read));
            }
        };
        $started = microtime(true);

        if ($notifiers === []) {
            if (Notifications::reasons() === []) {
                $this->components->warn('No reasons are configured in atp-appview.notifications.reasons.');

                return self::SUCCESS;
            }

            $added = $indexer->build($this->argument('collection'), $progress, $since, $until);
        } else {
            $added = 0;

            foreach ($notifiers as $class) {
                if (! is_a($class, Notifier::class, true)) {
                    $this->components->error("{$class} is not a class implementing ".Notifier::class.'.');

                    return self::INVALID;
                }
            }

            foreach ($notifiers as $class) {
                $added += $indexer->replay(app($class), $this->argument('collection'), $progress, $since, $until, (bool) $this->option('announce'));
            }
        }

        $this->components->info(sprintf('Added %s notifications in %.1f s.', number_format($added), microtime(true) - $started));

        return self::SUCCESS;
    }

    protected function time(string $option): ?Carbon
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? Carbon::parse($value, 'UTC') : null;
    }
}
