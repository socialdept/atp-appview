<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Index\AccountStatus;

class PurgeInactiveCommand extends Command
{
    protected $signature = 'appview:purge-inactive
                            {--days= : Days inactive, defaults to atp-appview.index.inactive.purge_after_days}
                            {--limit=500 : Accounts per run}
                            {--dry-run : Report what would be tombstoned without writing}';

    protected $description = 'Tombstone the hidden records of accounts inactive for too long';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('atp-appview.index.inactive.purge_after_days', 180));
        $dryRun = (bool) $this->option('dry-run');

        $status = new AccountStatus(DB::connection(config('atp-appview.index.connection')));
        $counts = $status->purge(Carbon::now('UTC')->subDays(max(1, $days)), (int) $this->option('limit'), $dryRun);

        foreach ($counts as $did => $count) {
            $this->components->twoColumnDetail($did, number_format($count).' records');
        }

        $this->components->info(sprintf(
            '%s accounts inactive for over %d days, %s records %s.',
            number_format(count($counts)),
            $days,
            number_format(array_sum($counts)),
            $dryRun ? 'would be tombstoned' : 'tombstoned',
        ));

        return self::SUCCESS;
    }
}
