<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Ingest\RepoDiscovery;
use SocialDept\AtpAppview\Ingest\TapAdmin;
use Throwable;

class DiscoverCommand extends Command
{
    protected $signature = 'appview:discover
                            {collection?* : Collections to discover, defaults to atp-appview.ingest.collections}
                            {--source= : A relay or Light Rail URL, defaults to atp-appview.ingest.discovery_url}
                            {--chunk=1000 : DIDs per request to Tap}
                            {--dry-run : Count repos without adding them to Tap}';

    protected $description = 'Find repos with records in each collection and have Tap backfill and follow them';

    protected const NSID = '/^[a-zA-Z][a-zA-Z0-9-]*(\.[a-zA-Z0-9-]+)+(\.[a-zA-Z][a-zA-Z0-9]*)$/';

    public function handle(TapAdmin $tap): int
    {
        $collections = $this->argument('collection') ?: (array) config('atp-appview.ingest.collections', []);

        if ($collections === []) {
            $this->components->error('No collections to discover. Pass them as arguments or set atp-appview.ingest.collections.');

            return self::FAILURE;
        }

        foreach ($collections as $collection) {
            if (! preg_match(self::NSID, (string) $collection)) {
                $this->components->error("\"{$collection}\" is not an NSID. listReposByCollection needs exact collections, without wildcards.");

                return self::FAILURE;
            }
        }

        $source = (string) ($this->option('source') ?: config('atp-appview.ingest.discovery_url'));
        $discovery = new RepoDiscovery($source);
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        /** @var array<string, true> $seen */
        $seen = [];
        $pending = [];

        try {
            foreach ($collections as $collection) {
                $found = 0;

                foreach ($discovery->dids($collection) as $did) {
                    $found++;

                    // A repo holding several of the collections only needs adding once.
                    if (isset($seen[$did])) {
                        continue;
                    }

                    $seen[$did] = true;
                    $pending[] = $did;

                    if (! $dryRun && count($pending) >= $chunk) {
                        $tap->addRepos($pending);
                        $pending = [];
                    }
                }

                $this->components->twoColumnDetail($collection, number_format($found).' repos');
            }

            if (! $dryRun && $pending !== []) {
                $tap->addRepos($pending);
            }
        } catch (Throwable $e) {
            $this->components->error("Discovery stopped: {$e->getMessage()}");

            return self::FAILURE;
        }

        $total = number_format(count($seen));

        $dryRun
            ? $this->components->info("{$total} unique repos found on {$source}. Nothing was added to Tap.")
            : $this->components->info("{$total} unique repos sent to Tap, which backfills each one and then follows it live.");

        return self::SUCCESS;
    }
}
