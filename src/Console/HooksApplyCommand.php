<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Ingest\ApplyHooks;

class HooksApplyCommand extends Command
{
    protected $signature = 'appview:hooks:apply
                            {collection? : Only this collection}
                            {--dry-run : Count what would be removed without removing it}';

    protected $description = 'Remove indexed records the ingest hooks now reject';

    public function handle(ApplyHooks $apply): int
    {
        if ((array) config('atp-appview.ingest.hooks', []) === []) {
            $this->components->warn('No hooks are configured in atp-appview.ingest.hooks.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $started = microtime(true);
        $removed = $apply->run($this->argument('collection'), $dryRun, function (int $read) {
            if ($read % 10000 === 0) {
                $this->components->twoColumnDetail('Records read', number_format($read));
            }
        });

        foreach ($removed as $hook => $reasons) {
            foreach ($reasons as $reason => $count) {
                $this->components->twoColumnDetail("{$hook}: {$reason}", number_format($count));
            }
        }

        $total = array_sum(array_map('array_sum', $removed));
        $this->components->info(sprintf($dryRun ? 'Would remove %s records (%.1f s).' : 'Removed %s records in %.1f s.', number_format($total), microtime(true) - $started));

        return self::SUCCESS;
    }
}
