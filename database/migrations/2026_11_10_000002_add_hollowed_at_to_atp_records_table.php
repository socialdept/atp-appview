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
     * When appview:prune cleared a record's body to save space, keeping the
     * row, its values and its counts. The body is fetched again when read.
     * Null for every record holding its body, so the column costs nothing
     * until retention.cold is set. No index: the age scan reads the sort
     * index and skips these rows.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasColumn('atp_records', 'hollowed_at')) {
            $schema->table('atp_records', fn (Blueprint $table) => $table->dateTime('hollowed_at')->nullable());
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn('atp_records', 'hollowed_at')) {
            $schema->table('atp_records', fn (Blueprint $table) => $table->dropColumn('hollowed_at'));
        }
    }
};
