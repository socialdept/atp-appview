<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Backfill\Backfills;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Collections;
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

    public function handle(TapAdmin $tap, Blocklist $blocklist): int
    {
        if (Backfills::reconciles()) {
            $this->components->error('With Jetstream there is no Tap to hand repos to. Run appview:backfill with each collection instead, which discovers the repos and reads them from their PDSes.');

            return self::FAILURE;
        }

        $collections = $this->argument('collection') ?: Collections::followed();

        if ($collections === []) {
            $this->components->error('No collections to discover. Pass them as arguments or set atp-appview.ingest.collections.');

            return self::FAILURE;
        }

        foreach ($collections as $collection) {
            if (Collections::isCompanion((string) $collection)) {
                $this->components->error("{$collection} is a companion collection, indexed only for repos already followed, so it is never discovered.");

                return self::FAILURE;
            }

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
        $skipped = 0;

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

                    if (count($pending) >= $chunk) {
                        $skipped += $this->send($tap, $blocklist, $pending, $dryRun);
                        $pending = [];
                    }
                }

                $this->components->twoColumnDetail($collection, number_format($found).' repos');
            }

            if ($pending !== []) {
                $skipped += $this->send($tap, $blocklist, $pending, $dryRun);
            }
        } catch (Throwable $e) {
            $this->components->error("Discovery stopped: {$e->getMessage()}");

            return self::FAILURE;
        }

        $total = number_format(count($seen) - $skipped);

        if ($skipped > 0) {
            $this->components->twoColumnDetail('Left out by the blocklist', number_format($skipped).' repos');
        }

        $dryRun
            ? $this->components->info("{$total} unique repos found on {$source}. Nothing was added to Tap.")
            : $this->components->info("{$total} unique repos sent to Tap, which backfills each one and then follows it live.");

        return self::SUCCESS;
    }

    /**
     * Send one chunk to Tap, leaving out repos a blanket rule blocks.
     *
     * @param  array<int, string>  $dids
     * @return int How many were left out
     */
    protected function send(TapAdmin $tap, Blocklist $blocklist, array $dids, bool $dryRun): int
    {
        $allowed = $blocklist->withoutBlockedRepos($dids);

        if (! $dryRun && $allowed !== []) {
            $tap->addRepos($allowed);
        }

        return count($dids) - count($allowed);
    }
}
