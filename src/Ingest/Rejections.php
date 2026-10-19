<?php

namespace SocialDept\AtpAppview\Ingest;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;

/**
 * What ingest hooks rejected, tallied by hook, collection and reason with
 * the latest record, so an operator can see what is being dropped without
 * every rejected record being stored.
 */
class Rejections
{
    public const TABLE = 'atp_ingest_rejections';

    public function record(string $hook, RecordEvent $event, string $reason): void
    {
        $connection = $this->connection();
        $mysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);

        $connection->table(self::TABLE)->upsert([[
            'hook' => $hook,
            'collection' => $event->collection,
            'reason' => mb_substr($reason, 0, 255),
            'total' => 1,
            'last_uri' => $event->uri(),
            'last_rejected_at' => Carbon::now('UTC'),
        ]], ['hook', 'collection', 'reason'], [
            'total' => DB::raw($mysql ? 'total + values(total)' : self::TABLE.'.total + excluded.total'),
            'last_uri' => DB::raw($mysql ? 'values(last_uri)' : 'excluded.last_uri'),
            'last_rejected_at' => DB::raw($mysql ? 'values(last_rejected_at)' : 'excluded.last_rejected_at'),
        ]);
    }

    /**
     * @return array<int, array{hook: string, collection: string, reason: string, total: int, last_uri: string|null, last_rejected_at: string}>
     */
    public function all(): array
    {
        return $this->connection()->table(self::TABLE)->orderByDesc('last_rejected_at')->get()
            ->map(fn ($row) => [...(array) $row, 'total' => (int) $row->total])
            ->all();
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
