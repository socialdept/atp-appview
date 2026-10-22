<?php

namespace SocialDept\AtpAppview\Ingest;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where each websocket source stands, such as `jetstream` or a labeler's
 * `labels:did:plc:…`. Saved in the same transaction as the batch it ends, so
 * a crash never skips a batch, and at worst sees one again.
 */
class Cursors
{
    public function get(string $source): ?int
    {
        $cursor = $this->connection()->table('atp_ingest_cursors')->where('source', $source)->value('cursor');

        return $cursor === null ? null : (int) $cursor;
    }

    public function set(string $source, int $cursor): void
    {
        $this->connection()->table('atp_ingest_cursors')->upsert(
            [['source' => $source, 'cursor' => $cursor, 'updated_at' => Carbon::now('UTC')->format('Y-m-d H:i:s')]],
            ['source'],
            ['cursor', 'updated_at'],
        );
    }

    public function forget(string $source): void
    {
        $this->connection()->table('atp_ingest_cursors')->where('source', $source)->delete();
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
