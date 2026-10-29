<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    /**
     * appview:prune finds a collection's tombstones older than its window.
     * The tombstone index on Postgres and SQLite is in sort order, so that
     * read walks every tombstone of the collection. A partial index by the
     * time of the delete reads only those due. MySQL and MariaDB already
     * have a plain index on the collection and deleted_at.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        if (! in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $connection->statement('create index if not exists atp_records_tombstones_by_age on atp_records (collection, deleted_at) where deleted_at is not null');
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        if (! in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $connection->statement('drop index if exists atp_records_tombstones_by_age');
    }
};
