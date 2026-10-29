<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    /**
     * MySQL and MariaDB have no partial indexes, so the hidden and tombstone
     * indexes on Postgres and SQLite were skipped there, and a list of a
     * collection's hidden records or tombstones, newest first, walked the
     * whole collection: 730 to 910 ms for 40 among 150,000. Plain indexes on
     * the collection and the column find just those rows, and sorting a few
     * dozen by sort_at is cheap, so the same lists take about a millisecond.
     *
     * A migration of its own, since installs on MySQL already ran the partial
     * index migration and found nothing to do.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['hidden_at', 'deleted_at'] as $column) {
            if (! $schema->hasIndex('atp_records', ['collection', $column])) {
                $schema->table('atp_records', fn (Blueprint $table) => $table->index(['collection', $column]));
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['hidden_at', 'deleted_at'] as $column) {
            if ($schema->hasIndex('atp_records', ['collection', $column])) {
                $schema->table('atp_records', fn (Blueprint $table) => $table->dropIndex(['collection', $column]));
            }
        }
    }
};
