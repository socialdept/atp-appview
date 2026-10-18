<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    /**
     * appview:reconcile picks repos never checked first, then the least
     * recently checked. Postgres puts nulls last in an ascending index, so the
     * plain index could not serve that order and every run read the whole
     * table. Built nulls first, it serves the run forwards, and a list of
     * the latest checks with never-checked repos last backwards.
     *
     * MySQL, MariaDB and SQLite already sort nulls first, so their index
     * stays as it is.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('drop index if exists atp_identities_reconciled_at_index');
        $connection->statement('create index atp_identities_reconciled_at_index on atp_identities (reconciled_at nulls first)');
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('drop index if exists atp_identities_reconciled_at_index');
        $connection->statement('create index atp_identities_reconciled_at_index on atp_identities (reconciled_at)');
    }
};
