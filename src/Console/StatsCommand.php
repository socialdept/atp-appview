<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Index\IndexStats;

class StatsCommand extends Command
{
    protected $signature = 'appview:stats';

    protected $description = 'Count the records, hidden records, tombstones and repos in the index';

    public function handle(IndexStats $stats): int
    {
        $started = microtime(true);
        $stats->refresh();
        $took = microtime(true) - $started;

        $rows = collect($stats->collections())
            ->map(fn (array $counts, string $nsid) => [$nsid, ...array_map(number_format(...), array_values($counts))])
            ->values()
            ->push(['All', ...array_map(number_format(...), array_values($stats->totals()))]);

        $this->table(['Collection', 'Records', 'Hidden', 'Tombstones', 'Repos'], $rows->all());
        $this->components->info(sprintf('Counted in %.2f s.', $took));

        return self::SUCCESS;
    }
}
