<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Index\Refs;

class RefsCommand extends Command
{
    protected $signature = 'appview:refs';

    protected $description = 'Build the references of reference fields added since the last run, and drop those of removed ones';

    public function handle(Refs $refs): int
    {
        $started = microtime(true);

        $result = $refs->work(function (string $collection, string $path, int $done) {
            if ($done % 50000 < 1000) {
                $this->components->twoColumnDetail("{$collection} {$path}", number_format($done).' records');
            }
        });

        foreach ($result['built'] as $field) {
            $this->components->info("Built {$field}.");
        }

        foreach ($result['dropped'] as $field) {
            $this->components->info("Dropped {$field}.");
        }

        if ($result['built'] === [] && $result['dropped'] === []) {
            $this->components->info('Every reference field is built.');
        } else {
            $this->components->info(sprintf('Done in %.1f s.', microtime(true) - $started));
        }

        return self::SUCCESS;
    }
}
