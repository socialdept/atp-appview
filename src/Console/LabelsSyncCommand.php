<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Labels\SubjectSync;
use SocialDept\AtpAppview\Labels\Tack;
use Throwable;

class LabelsSyncCommand extends Command
{
    protected $signature = 'appview:labels:sync
                            {--limit=1000 : Accounts to register per run}';

    protected $description = 'Register the accounts the index knows with Tack, so their labels arrive';

    public function handle(SubjectSync $sync): int
    {
        if (! Tack::configured()) {
            $this->components->info('No Tack configured in atp-appview.labels.tack.url. Nothing to sync.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->run((int) $this->option('limit'));
        } catch (Throwable $e) {
            $this->components->error('Tack did not answer: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$result['accounts']} accounts registered with Tack, {$result['uris']} late records sent.");

        return self::SUCCESS;
    }
}
