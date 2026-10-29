<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Features\AppView;

/**
 * What appview:prune removes from the index, as atp-appview.retention asks:
 * tombstones older than their collection's window.
 *
 * Each pass reads a chunk of rows by an index on their collection and age,
 * deletes them by primary key in one transaction with what references them
 * (their field values, and whatever enabled features keep through
 * afterPrune, such as notifications), and stops at the deadline, so a run
 * holds no lock for long and the next one picks up where it stopped.
 */
class Retention
{
    public function __construct(
        protected ?Connection $connection = null,
    ) {
        $this->connection ??= DB::connection(config('atp-appview.index.connection'));
    }

    /**
     * Days a tombstone in this collection is kept, from
     * atp-appview.retention.tombstones, or null to keep it.
     */
    public static function tombstoneDays(string $collection): ?int
    {
        return self::days((array) config('atp-appview.retention.tombstones', []), $collection);
    }

    /**
     * The collections the index holds, by a seek per collection along the
     * collection index, so it never reads every row.
     *
     * @return array<int, string>
     */
    public function collections(): array
    {
        $collections = [];
        $after = '';

        while (($next = $this->connection->table('atp_records')->where('collection', '>', $after)->min('collection')) !== null) {
            $collections[] = $after = (string) $next;
        }

        return $collections;
    }

    /**
     * Delete tombstones older than their collection's window, until the
     * deadline.
     *
     * @param  float  $until  A microtime(true) deadline
     * @return array{counts: array<string, int>, done: bool} Rows deleted, or found with $dryRun, by collection
     */
    public function compactTombstones(int $chunk, float $until, bool $dryRun = false): array
    {
        $counts = [];

        foreach ($this->collections() as $collection) {
            $days = self::tombstoneDays($collection);

            if ($days === null) {
                continue;
            }

            $query = fn () => $this->connection->table('atp_records')
                ->where('collection', $collection)
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<', self::cutoff($days));

            if (! $this->drain($query, $collection, $chunk, $until, $dryRun, $counts)) {
                return ['counts' => $counts, 'done' => false];
            }
        }

        return ['counts' => $counts, 'done' => true];
    }

    /**
     * Delete the rows a query finds, a chunk at a time, counting them by
     * collection. With $dryRun, only count.
     *
     * @param  \Closure(): \Illuminate\Database\Query\Builder  $query
     * @param  array<string, int>  $counts
     * @return bool Whether it finished before the deadline
     */
    protected function drain(\Closure $query, string $collection, int $chunk, float $until, bool $dryRun, array &$counts): bool
    {
        if ($dryRun) {
            $found = $query()->count();

            if ($found > 0) {
                $counts[$collection] = ($counts[$collection] ?? 0) + $found;
            }

            return true;
        }

        do {
            if (microtime(true) >= $until) {
                return false;
            }

            $rows = $query()->limit($chunk)->get(['uri', 'id']);

            if ($rows->isNotEmpty()) {
                $this->delete($rows->pluck('uri')->all(), $rows->pluck('id')->map(fn ($id) => (int) $id)->all());
                $counts[$collection] = ($counts[$collection] ?? 0) + $rows->count();
            }
        } while ($rows->count() === $chunk);

        return true;
    }

    /**
     * Delete records outright, with their values and what features keep
     * about them.
     *
     * @param  array<int, string>  $uris
     * @param  array<int, int>  $ids
     */
    public function delete(array $uris, array $ids): void
    {
        $this->connection->transaction(function () use ($uris, $ids) {
            $this->connection->table('atp_records')->whereIn('uri', $uris)->delete();
            app(RecordValues::class)->dropRecords($this->connection, $ids);

            foreach (app(AppView::class)->afterPruneCallbacks() as $afterPrune) {
                $afterPrune($this->connection, $uris);
            }
        });
    }

    /**
     * Days from a per-collection map with a '*' default. Anything but a
     * positive number of days keeps everything.
     *
     * @param  array<string, mixed>  $map
     */
    protected static function days(array $map, string $collection): ?int
    {
        // Read whole: NSIDs contain dots, which config() would take as nesting.
        $days = array_key_exists($collection, $map) ? $map[$collection] : ($map['*'] ?? null);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    protected static function cutoff(int $days): string
    {
        return Carbon::now('UTC')->subDays($days)->format('Y-m-d H:i:s');
    }
}
