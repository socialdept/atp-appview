<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SocialDept\AtpAppview\Index\RecordIndexer;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        match ($connection->getDriverName()) {
            'pgsql' => $this->recordsAsJsonb($connection),
            'mysql', 'mariadb' => $this->datesAsDatetime($connection, toDatetime: true),
            default => null,
        };
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        match ($connection->getDriverName()) {
            'pgsql' => $connection->statement('alter table atp_records alter column record type json using record::json'),
            'mysql', 'mariadb' => $this->datesAsDatetime($connection, toDatetime: false),
            default => null,
        };
    }

    /**
     * jsonb is stored parsed, so fields can be indexed and read without
     * re-parsing. It cannot hold an escaped NUL, and json holding one breaks
     * every field lookup on the table, so those records are cleaned first,
     * the way the indexer cleans new ones.
     */
    protected function recordsAsJsonb(\Illuminate\Database\Connection $connection): void
    {
        $dirty = $connection->table('atp_records')
            ->whereRaw("record::text like '%\\\\u0000%'")
            ->get(['uri', 'record']);

        foreach ($dirty as $row) {
            $record = json_decode($row->record, true);

            if (is_array($record)) {
                $connection->table('atp_records')->where('uri', $row->uri)->update([
                    'record' => json_encode(RecordIndexer::withoutNullCharacters($record), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        $connection->statement('alter table atp_records alter column record type jsonb using record::jsonb');
    }

    /**
     * MySQL's TIMESTAMP holds only 1970 to 2038 and converts through the
     * session timezone. DATETIME does neither. The conversion reads each
     * TIMESTAMP in the session timezone, so it runs in the application's own
     * session, the one its values were written in. Pinning another timezone
     * here would shift every value by the difference.
     */
    protected function datesAsDatetime(\Illuminate\Database\Connection $connection, bool $toDatetime): void
    {
        $schema = Schema::connection($this->getConnection());
        $type = $toDatetime ? 'dateTime' : 'timestamp';

        $schema->table('atp_records', function (Blueprint $table) use ($type) {
            $table->{$type}('indexed_at')->change();
            $table->{$type}('sort_at', 6)->nullable()->change();
            $table->{$type}('deleted_at')->nullable()->change();
        });

        $schema->table('atp_identities', function (Blueprint $table) use ($type) {
            $table->{$type}('updated_at')->change();
            $table->{$type}('pds_resolved_at')->nullable()->change();
        });

        $schema->table('atp_blocks', function (Blueprint $table) use ($type) {
            $table->{$type}('last_blocked_at')->nullable()->change();
            $table->{$type}('created_at')->nullable()->change();
            $table->{$type}('updated_at')->nullable()->change();
        });
    }
};
