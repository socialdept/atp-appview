<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Standing\Standing;

class StandingRefreshCommand extends Command
{
    protected $signature = 'appview:standing:refresh
                            {subjects?* : DIDs or AT-URIs to refresh now}
                            {--stale : Refresh what has gone stale}
                            {--limit=500 : At most this many stale subjects}';

    protected $description = 'Refresh the metrics, verification and scores of accounts and records';

    public function handle(Standing $standing): int
    {
        $subjects = (array) $this->argument('subjects');

        if ($this->option('stale')) {
            $subjects = [...$subjects, ...$standing->stale((int) $this->option('limit'))];
        }

        if ($subjects === []) {
            $this->components->info('Nothing to refresh. Name subjects, or pass --stale.');

            return self::SUCCESS;
        }

        foreach (array_chunk(array_values(array_unique($subjects)), max(1, (int) config('atp-appview.standing.batch', 25))) as $batch) {
            $standing->refresh($batch);
        }

        $this->components->info('Refreshed '.count(array_unique($subjects)).' subjects.');

        return self::SUCCESS;
    }
}
