<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $mysql = in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        // Which indexed records point at which, through which field, for the
        // counts and viewer state of hydrated views. Only live records have
        // rows, and `hidden` mirrors the source record's hidden_at, so a count
        // is read from an index without touching atp_records.
        $schema->create('atp_record_refs', function (Blueprint $table) use ($mysql) {
            // ASCII by definition, and one byte a character on MySQL keeps the
            // indexes within InnoDB's key limit, as on atp_records.
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

            $ascii($table->string('source_uri', 512));
            $ascii($table->string('source_did', 255));
            $ascii($table->string('source_collection', 317));
            $ascii($table->string('path', 128));
            $ascii($table->string('target_uri', 512));
            $table->boolean('hidden')->default(false);

            $table->primary(['source_uri', 'path', 'target_uri']);
            // Counts: live records in a collection pointing at a page of targets.
            $table->index(['target_uri', 'source_collection', 'path', 'hidden'], 'atp_record_refs_counts');
            // Viewer state, read from the index alone, and hiding or dropping
            // an account's references.
            $table->index(['source_did', 'source_collection', 'path', 'target_uri', 'hidden', 'source_uri'], 'atp_record_refs_viewer');
        });

        // The reference fields whose rows have been built, so a field added
        // later is built for records already indexed, and a removed one dropped.
        $schema->create('atp_ref_paths', function (Blueprint $table) use ($mysql) {
            $collection = $table->string('collection', 317);
            $path = $table->string('path', 128);

            if ($mysql) {
                $collection->charset('ascii')->collation('ascii_bin');
                $path->charset('ascii')->collation('ascii_bin');
            }

            $table->dateTime('built_at');
            $table->primary(['collection', 'path']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('atp_ref_paths');
        $schema->dropIfExists('atp_record_refs');
    }
};
