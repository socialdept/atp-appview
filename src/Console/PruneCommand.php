<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Index\Retention;

class PruneCommand extends Command
{
    protected $signature = 'appview:prune
                            {--chunk=1000 : Rows per statement}
                            {--seconds=50 : Stop after this long, to pick up again on the next run}
                            {--dry-run : Count what would be removed without removing it}';

    protected $description = 'Remove what atp-appview.retention lets go: old tombstones';

    public function handle(): int
    {
        $retention = new Retention();
        $dryRun = (bool) $this->option('dry-run');
        $until = microtime(true) + max(1, (int) $this->option('seconds'));
        $chunk = max(1, (int) $this->option('chunk'));

        $result = $retention->compactTombstones($chunk, $until, $dryRun);

        foreach ($result['counts'] as $collection => $count) {
            $this->components->twoColumnDetail($collection, number_format($count).' tombstones');
        }

        $this->components->info(sprintf(
            '%s tombstones %s.%s',
            number_format(array_sum($result['counts'])),
            $dryRun ? 'would be deleted' : 'deleted',
            $result['done'] ? '' : ' Stopped at the time limit, the next run continues.',
        ));

        return self::SUCCESS;
    }
}
