<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Claims\Claims;

class ClaimsRefreshCommand extends Command
{
    protected $signature = 'appview:claims:refresh
                            {uris?* : AT-URIs of records to check now}
                            {--stale : Check what is due}
                            {--limit=500 : At most this many due records}';

    protected $description = 'Check the claims records make, in the queue, one job per host';

    public function handle(Claims $claims): int
    {
        $uris = (array) $this->argument('uris');

        if ($this->option('stale')) {
            $uris = [...$uris, ...$claims->due((int) $this->option('limit'))];
        }

        if ($uris === []) {
            $this->components->info('Nothing to check. Name records, or pass --stale.');

            return self::SUCCESS;
        }

        $this->components->info('Queued checks for '.$claims->queue($uris).' records.');

        return self::SUCCESS;
    }
}
