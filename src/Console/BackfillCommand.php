<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Backfill\Backfills;
use SocialDept\AtpAppview\Ingest\Collections;
use Throwable;

class BackfillCommand extends Command
{
    protected $signature = 'appview:backfill
                            {collection* : Collections to backfill, each already in atp-appview.ingest.collections or ingest.companions}
                            {--source= : A relay or Light Rail URL, defaults to atp-appview.ingest.discovery_url}';

    protected $description = 'Have Tap backfill collections the index started ingesting, and track their progress';

    public function handle(Backfills $backfills): int
    {
        $status = self::SUCCESS;

        foreach ((array) $this->argument('collection') as $collection) {
            if (! Collections::ingests($collection)) {
                $this->components->error("{$collection} is not ingested. Add it to atp-appview.ingest.collections or ingest.companions, and to Tap's collection filters, first.");
                $status = self::FAILURE;

                continue;
            }

            try {
                $backfill = $backfills->start($collection, $this->option('source') ?: null, fn (int $found) => $this->components->twoColumnDetail($collection, number_format($found).' repos found'));
            } catch (Throwable $e) {
                $this->components->error("{$collection}: {$e->getMessage()}");
                $status = self::FAILURE;

                continue;
            }

            $methods = $backfill->repos()->selectRaw('method, count(*) as n')->groupBy('method')->pluck('n', 'method');

            foreach (['resync' => 'Followed, resynced by Tap', 'add' => 'New to Tap, added', 'readd' => 'Removed and added (this Tap cannot resync)'] as $method => $label) {
                if (($methods[$method] ?? 0) > 0) {
                    $this->components->twoColumnDetail($label, number_format($methods[$method]).' repos');
                }
            }

            $this->components->info("Backfilling {$collection}. appview:backfill:work finishes it once Tap is done.");
        }

        return $status;
    }
}
