<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Labels\SubjectSync;
use SocialDept\AtpAppview\Labels\Tack;
use Throwable;

class LabelsSyncCommand extends Command
{
    protected $signature = 'appview:labels:sync
                            {--limit=1000 : Accounts to register per run}';

    protected $description = 'Fetch the labels the accounts the index knows already have, through Tack or label backfills, and drop expired labels';

    public function handle(SubjectSync $sync): int
    {
        if (Labels::streaming()) {
            $result = $sync->run((int) $this->option('limit'));
            $this->components->info("{$result['accounts']} accounts and {$result['uris']} late records queued for label backfill, {$result['labelers']} labelers rechecked, {$result['expired']} expired labels dropped.");

            return self::SUCCESS;
        }

        if (! Tack::configured()) {
            $expired = SubjectSync::expire();
            $this->components->info("No Tack configured in atp-appview.labels.tack.url. {$expired} expired labels dropped.");

            return self::SUCCESS;
        }

        try {
            $result = $sync->run((int) $this->option('limit'));
        } catch (Throwable $e) {
            $this->components->error('Tack did not answer: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$result['accounts']} accounts registered with Tack, {$result['uris']} late records sent, {$result['expired']} expired labels dropped.");

        return self::SUCCESS;
    }
}
