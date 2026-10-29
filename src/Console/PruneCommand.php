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

    protected $description = 'Remove what atp-appview.retention lets go: old tombstones, and records past their collection\'s max_age';

    public function handle(): int
    {
        $retention = new Retention();
        $dryRun = (bool) $this->option('dry-run');
        $until = microtime(true) + max(1, (int) $this->option('seconds'));
        $chunk = max(1, (int) $this->option('chunk'));
        $done = true;
        $totals = [];

        // Each step runs only while time is left, in the order given.
        $steps = [
            'tombstones' => fn () => $retention->compactTombstones($chunk, $until, $dryRun),
            'expired records' => fn () => $retention->expire($chunk, $until, $dryRun),
        ];

        foreach ($steps as $label => $step) {
            $result = $done ? $step() : ['counts' => [], 'done' => false];
            $done = $result['done'];
            $totals[$label] = array_sum($result['counts']);

            foreach ($result['counts'] as $collection => $count) {
                $this->components->twoColumnDetail($collection, number_format($count).' '.$label);
            }
        }

        $this->components->info(sprintf(
            '%s %s.%s',
            implode(', ', array_map(fn (string $label, int $count) => number_format($count).' '.$label, array_keys($totals), $totals)),
            $dryRun ? 'would be deleted' : 'deleted',
            $done ? '' : ' Stopped at the time limit, the next run continues.',
        ));

        return self::SUCCESS;
    }
}
