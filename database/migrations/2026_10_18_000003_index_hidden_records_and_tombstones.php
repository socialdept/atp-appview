<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    /**
     * Hidden records and tombstones are a sliver of each collection. Listed
     * newest first through the main sort index, a query walks the whole
     * collection to find them: 406 ms for 19 hidden records among 229,000.
     * Partial indexes hold only that sliver, so the list reads it directly.
     *
     * MySQL and MariaDB have no partial indexes, so they get plain ones in a
     * later migration instead.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        if (! in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $connection->statement('create index if not exists atp_records_hidden_in_sort_order on atp_records (collection, sort_at, uri) where hidden_at is not null');
        $connection->statement('create index if not exists atp_records_tombstones_in_sort_order on atp_records (collection, sort_at, uri) where deleted_at is not null');
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        if (! in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $connection->statement('drop index if exists atp_records_hidden_in_sort_order');
        $connection->statement('drop index if exists atp_records_tombstones_in_sort_order');
    }
};
