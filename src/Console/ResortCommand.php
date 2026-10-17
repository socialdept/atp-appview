<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SocialDept\AtpAppview\Index\RecordIndexer;
use SocialDept\AtpAppview\Index\SortAt;
use SocialDept\AtpAppview\Models\Record;

class ResortCommand extends Command
{
    protected $signature = 'appview:resort
                            {collection?* : Collections to recompute, defaults to all}
                            {--chunk=1000 : Records per batch}';

    protected $description = 'Recompute sort_at for indexed records, after changing atp-appview.index.sort_fields';

    public function handle(): int
    {
        $query = Record::withHidden()->whereNotNull('record');

        if ($collections = $this->argument('collection')) {
            $query->whereIn('collection', $collections);
        }

        $bar = $this->output->createProgressBar((clone $query)->count());
        $changed = 0;

        // By uri, so rows rewritten along the way are neither skipped nor seen twice.
        $query->select(['uri', 'collection', 'rkey', 'record', 'indexed_at', 'sort_at'])
            ->chunkById(max(1, (int) $this->option('chunk')), function ($records) use ($bar, &$changed) {
                $connection = Record::withHidden()->getConnection();

                $connection->transaction(function () use ($records, $connection, &$changed) {
                    foreach ($records as $record) {
                        // Capped at the index time, as it was when the record was indexed.
                        $sortAt = SortAt::for($record->record, $record->rkey, Carbon::parse($record->getRawOriginal('indexed_at'), 'UTC'), RecordIndexer::sortFields($record->collection));

                        if ($sortAt !== Carbon::parse($record->getRawOriginal('sort_at'), 'UTC')->format('Y-m-d H:i:s.u')) {
                            $connection->table('atp_records')->where('uri', $record->uri)->update(['sort_at' => $sortAt]);
                            $changed++;
                        }
                    }
                });

                $bar->advance($records->count());
            }, 'uri', 'uri');

        $bar->finish();
        $this->newLine(2);
        $this->components->info(number_format($changed).' records resorted.');

        return self::SUCCESS;
    }
}
