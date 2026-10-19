<?php

namespace SocialDept\AtpAppview\Console;

use Illuminate\Console\Command;
use SocialDept\AtpAppview\Ingest\FailedDeliveries;
use SocialDept\AtpAppview\Models\FailedDelivery;

class FailedDeliveriesCommand extends Command
{
    protected $signature = 'appview:failed-deliveries
                            {--retry : Run every failed delivery through ingest again}
                            {--forget : Drop every failed delivery}
                            {--prune : Drop those past atp-appview.failed_deliveries.keep_days and max}
                            {--collection= : Only failed deliveries of this collection}';

    protected $description = 'Show, retry, forget or prune deliveries the handlers could not take';

    public function handle(FailedDeliveries $deliveries): int
    {
        if ($this->option('prune')) {
            $this->components->info(number_format($deliveries->prune()).' failed deliveries pruned.');

            return self::SUCCESS;
        }

        $query = fn () => FailedDelivery::query()->when($this->option('collection'), fn ($query, $collection) => $query->where('collection', $collection));

        if ($this->option('forget')) {
            $this->components->info(number_format($query()->delete()).' failed deliveries forgotten.');

            return self::SUCCESS;
        }

        if ($this->option('retry')) {
            $done = 0;
            $failed = 0;

            $query()->orderBy('id')->chunkById(100, function ($chunk) use ($deliveries, &$done, &$failed) {
                foreach ($chunk as $delivery) {
                    $deliveries->retry($delivery) ? $done++ : $failed++;
                }
            });

            $this->components->info("{$done} went through, {$failed} failed again.");

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $rows = $query()->selectRaw("coalesce(collection, 'identity') as collection, error_class, count(*) as total, max(failed_at) as latest")
            ->groupBy('collection', 'error_class')->orderByDesc('total')->get();

        if ($rows->isEmpty()) {
            $this->components->info('No failed deliveries.');

            return self::SUCCESS;
        }

        $this->table(['Collection', 'Error', 'Count', 'Latest'], $rows->map(fn ($row) => [
            $row->collection, class_basename($row->error_class), number_format($row->total), $row->latest,
        ])->all());

        return self::SUCCESS;
    }
}
