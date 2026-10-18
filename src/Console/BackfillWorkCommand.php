<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Backfill\Backfills;

class BackfillWorkCommand extends Command
{
    protected $signature = 'appview:backfill:work';

    protected $description = 'Start backfills for newly ingested collections and finish those Tap is done with';

    public function handle(Backfills $backfills): int
    {
        foreach ($backfills->work() as $collection) {
            $this->components->info("Started backfilling {$collection}.");
        }

        return self::SUCCESS;
    }
}
