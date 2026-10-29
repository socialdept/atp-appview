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

    protected $description = 'Remove what atp-appview.retention lets go: old tombstones, records past their collection\'s max_age, and the bodies of cold records';

    public function handle(): int
    {
        $retention = new Retention();
        $dryRun = (bool) $this->option('dry-run');
        $until = microtime(true) + max(1, (int) $this->option('seconds'));
        $chunk = max(1, (int) $this->option('chunk'));
        $done = true;
        $totals = [];

        // Each step runs only while time is left, in the order given:
        // deleting first, so no body is cleared on a row about to go.
        $steps = [
            'tombstones' => fn () => $retention->compactTombstones($chunk, $until, $dryRun),
            'expired records' => fn () => $retention->expire($chunk, $until, $dryRun),
            'cold records' => fn () => $retention->hollow($chunk, $until, $dryRun),
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
            '%s %s tombstones and %s expired records, %s %s cold records.%s',
            $dryRun ? 'Would delete' : 'Deleted',
            number_format($totals['tombstones']),
            number_format($totals['expired records']),
            $dryRun ? 'would hollow' : 'hollowed',
            number_format($totals['cold records']),
            $done ? '' : ' Stopped at the time limit, the next run continues.',
        ));

        return self::SUCCESS;
    }
}
