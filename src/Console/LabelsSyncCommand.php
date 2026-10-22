<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Labels\SubjectSync;
use SocialDept\AtpAppview\Labels\Tack;
use Throwable;

class LabelsSyncCommand extends Command
{
    protected $signature = 'appview:labels:sync
                            {--limit=1000 : Accounts to register per run}';

    protected $description = 'Register the accounts the index knows with Tack, or recheck the labelers streams follow, and drop expired labels';

    /** Labelers' declarations are rechecked this often with streams, as Tack does. */
    protected const DECLARATIONS_HOURS = 6;

    public function handle(SubjectSync $sync): int
    {
        if (Labels::streaming()) {
            $checked = Cache::add('atp-appview:labels:declarations-checked', true, now()->addHours(self::DECLARATIONS_HOURS)) ? $sync->refreshDeclarations() : 0;
            $expired = SubjectSync::expire();
            $this->components->info("{$checked} labelers rechecked, {$expired} expired labels dropped.");

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
