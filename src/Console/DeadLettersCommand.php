<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Ingest\DeadLetters;
use SocialDept\AtpAppview\Models\DeadLetter;

class DeadLettersCommand extends Command
{
    protected $signature = 'appview:dead-letters
                            {--retry : Run every dead letter through ingest again}
                            {--dismiss : Drop every dead letter}
                            {--prune : Drop those past atp-appview.dead_letters.keep_days and max}
                            {--collection= : Only dead letters of this collection}';

    protected $description = 'Show, retry, dismiss or prune events the handlers could not take';

    public function handle(DeadLetters $letters): int
    {
        if ($this->option('prune')) {
            $this->components->info(number_format($letters->prune()).' dead letters pruned.');

            return self::SUCCESS;
        }

        $query = fn () => DeadLetter::query()->when($this->option('collection'), fn ($query, $collection) => $query->where('collection', $collection));

        if ($this->option('dismiss')) {
            $this->components->info(number_format($query()->delete()).' dead letters dismissed.');

            return self::SUCCESS;
        }

        if ($this->option('retry')) {
            $done = 0;
            $failed = 0;

            $query()->orderBy('id')->chunkById(100, function ($chunk) use ($letters, &$done, &$failed) {
                foreach ($chunk as $letter) {
                    $letters->retry($letter) ? $done++ : $failed++;
                }
            });

            $this->components->info("{$done} went through, {$failed} failed again.");

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $rows = $query()->selectRaw("coalesce(collection, 'identity') as collection, error_class, count(*) as total, max(failed_at) as latest")
            ->groupBy('collection', 'error_class')->orderByDesc('total')->get();

        if ($rows->isEmpty()) {
            $this->components->info('No dead letters.');

            return self::SUCCESS;
        }

        $this->table(['Collection', 'Error', 'Count', 'Latest'], $rows->map(fn ($row) => [
            $row->collection, class_basename($row->error_class), number_format($row->total), $row->latest,
        ])->all());

        return self::SUCCESS;
    }
}
