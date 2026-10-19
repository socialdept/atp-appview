<?php

namespace SocialDept\AtpAppview\Ingest;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;

/**
 * What ingest repairs rewrote, tallied by repair and collection with the
 * latest record, so an operator can see how often each one is needed. The
 * records themselves carry their repairs in atp_records.repair.
 */
class Repairs
{
    public const TABLE = 'atp_ingest_repairs';

    /**
     * @param  int  $times  How many records of this collection the repair changed, $event the latest
     */
    public function record(string $repair, RecordEvent $event, int $times = 1): void
    {
        $connection = $this->connection();
        $mysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);

        $connection->table(self::TABLE)->upsert([[
            'repair' => $repair,
            'collection' => $event->collection,
            'total' => $times,
            'last_uri' => $event->uri(),
            'last_repaired_at' => Carbon::now('UTC'),
        ]], ['repair', 'collection'], [
            'total' => DB::raw($mysql ? 'total + values(total)' : self::TABLE.'.total + excluded.total'),
            'last_uri' => DB::raw($mysql ? 'values(last_uri)' : 'excluded.last_uri'),
            'last_repaired_at' => DB::raw($mysql ? 'values(last_repaired_at)' : 'excluded.last_repaired_at'),
        ]);
    }

    /**
     * @return array<int, array{repair: string, collection: string, total: int, last_uri: string|null, last_repaired_at: string}>
     */
    public function all(): array
    {
        return $this->connection()->table(self::TABLE)->orderByDesc('last_repaired_at')->get()
            ->map(fn ($row) => [...(array) $row, 'total' => (int) $row->total])
            ->all();
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
