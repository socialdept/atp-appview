<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        // AT URIs, DIDs, NSIDs and record keys are ASCII by definition. Storing
        // them as such on MySQL and MariaDB uses one byte a character instead
        // of utf8mb4's four, which keeps the index below within InnoDB's 3072
        // byte key limit.
        if (in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $schema->table('atp_records', function (Blueprint $table) {
                $table->string('uri', 512)->charset('ascii')->collation('ascii_bin')->change();
                $table->string('did', 255)->charset('ascii')->collation('ascii_bin')->change();
                $table->string('collection', 317)->charset('ascii')->collation('ascii_bin')->change();
                $table->string('rkey', 512)->charset('ascii')->collation('ascii_bin')->change();
            });
        }

        $schema->table('atp_records', function (Blueprint $table) {
            // When the record was made, for ordering: its createdAt, but never
            // later than when it was first seen. Ordering by indexed_at would
            // give every backfilled record the time of the backfill.
            $table->timestamp('sort_at', 6)->nullable()->after('indexed_at');
            $table->index(['collection', 'sort_at', 'uri']);
        });

        DB::connection($this->getConnection())->table('atp_records')->whereNull('sort_at')->update([
            'sort_at' => DB::raw('indexed_at'),
        ]);
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('atp_records', function (Blueprint $table) {
            $table->dropIndex(['collection', 'sort_at', 'uri']);
            $table->dropColumn('sort_at');
        });
    }
};
