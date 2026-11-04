<?php

namespace SocialDept\AtpAppview\Index;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How many records, hidden records, tombstones and repos the index holds,
 * per collection and overall.
 *
 * Counting distinct repos reads every record, so the counts are taken by
 * appview:stats on a schedule and read from atp_index_stats. Counting on
 * every write instead would have to follow each path that changes a record's
 * state (ingest, tombstones, hiding, purges, blocks) and drift whenever one
 * was missed. A recount cannot drift.
 */
class IndexStats
{
    public const TOTAL = '*';

    /** @var array<string, object>|null */
    protected ?array $rows = null;

    /**
     * Count the index again, in one pass over atp_records.
     *
     * Rows are grouped by collection and repo first, which databases hash in
     * memory. A plain count(distinct did) sorts every row instead: on Postgres
     * that spilled to disk and took twice as long.
     */
    public function refresh(): void
    {
        $connection = $this->connection();

        $rows = $connection->select(<<<'SQL'
            with per_repo as (
                select collection, did,
                    sum(case when deleted_at is null and hidden_at is null then 1 else 0 end) as records,
                    sum(case when deleted_at is null and hidden_at is not null then 1 else 0 end) as hidden,
                    sum(case when deleted_at is not null then 1 else 0 end) as tombstones
                from atp_records
                group by collection, did
            )
            select collection, sum(records) as records, sum(hidden) as hidden, sum(tombstones) as tombstones,
                sum(case when records > 0 then 1 else 0 end) as repos
            from per_repo
            group by collection
            union all
            select null, sum(records), sum(hidden), sum(tombstones),
                count(distinct case when records > 0 then did end)
            from per_repo
            SQL);

        $now = Carbon::now('UTC');

        $stats = array_map(fn (object $row) => [
            'collection' => $row->collection ?? self::TOTAL,
            'records' => (int) $row->records,
            'hidden' => (int) $row->hidden,
            'tombstones' => (int) $row->tombstones,
            'repos' => (int) $row->repos,
            'counted_at' => $now,
        ], $rows);

        $connection->transaction(function () use ($connection, $stats) {
            $connection->table('atp_index_stats')->delete();
            $connection->table('atp_index_stats')->insert($stats);
        });

        $this->rows = null;
    }

    /**
     * The last counts for each collection that holds anything, by NSID.
     *
     * @return array<string, array{records: int, hidden: int, tombstones: int, repos: int}>
     */
    public function collections(): array
    {
        return collect($this->rows())
            ->except(self::TOTAL)
            ->sortKeys()
            ->map(fn (object $row) => $this->shape($row))
            ->all();
    }

    /**
     * The last counts across every collection. Repos count once however
     * many collections they hold.
     *
     * @return array{records: int, hidden: int, tombstones: int, repos: int}
     */
    public function totals(): array
    {
        $row = $this->rows()[self::TOTAL] ?? null;

        return $row === null ? ['records' => 0, 'hidden' => 0, 'tombstones' => 0, 'repos' => 0] : $this->shape($row);
    }

    /**
     * When the counts were taken, or null if they never were.
     */
    public function countedAt(): ?Carbon
    {
        $at = ($this->rows()[self::TOTAL] ?? null)?->counted_at;

        return $at === null ? null : Carbon::parse($at, 'UTC');
    }

    /**
     * @return array{records: int, hidden: int, tombstones: int, repos: int}
     */
    protected function shape(object $row): array
    {
        return [
            'records' => (int) $row->records,
            'hidden' => (int) $row->hidden,
            'tombstones' => (int) $row->tombstones,
            'repos' => (int) $row->repos,
        ];
    }

    /**
     * The table, a row per collection and one for the total, read once: a
     * status page asks for the totals, each collection and the time apart.
     *
     * @return array<string, object>
     */
    protected function rows(): array
    {
        return $this->rows ??= $this->connection()->table('atp_index_stats')->get()->keyBy('collection')->all();
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
