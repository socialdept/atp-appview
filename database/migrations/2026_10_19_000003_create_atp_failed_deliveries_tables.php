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

        // Each step is skipped when already done. Before hook and reason were
        // shortened, this migration failed on MySQL after creating
        // atp_failed_deliveries, and MySQL keeps a table made before a
        // failure, so those installs must be able to run it again.

        // Events the handlers could not take, kept with what failed, so the
        // rest of their batch could be acknowledged and ingest moves on.
        $schema->hasTable('atp_failed_deliveries') || $schema->create('atp_failed_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->unsignedBigInteger('event_id')->nullable();
            $table->string('did', 255);
            $table->string('collection', 317)->nullable();
            $table->string('uri', 512)->nullable();
            $table->json('payload');
            $table->string('error_class', 255);
            $table->text('error');
            $table->unsignedInteger('retries')->default(0);
            $table->dateTime('failed_at');
            $table->dateTime('retried_at')->nullable();

            $table->index('failed_at');
            $table->index(['collection', 'failed_at']);
            $table->index('did');
        });

        // What ingest hooks rejected, tallied by hook, collection and reason,
        // with the latest record, so the table stays small. hook and reason
        // are 191 characters so the primary key fits InnoDB's 3072 byte limit
        // in utf8mb4: (191 + 317 + 191) * 4 = 2796 bytes.
        $schema->hasTable('atp_ingest_rejections') || $schema->create('atp_ingest_rejections', function (Blueprint $table) {
            $table->string('hook', 191);
            $table->string('collection', 317);
            $table->string('reason', 191);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('last_uri', 512)->nullable();
            $table->dateTime('last_rejected_at');

            $table->primary(['hook', 'collection', 'reason']);
        });

        // What ingest repairs rewrote, tallied the same way.
        $schema->hasTable('atp_ingest_repairs') || $schema->create('atp_ingest_repairs', function (Blueprint $table) {
            $table->string('repair', 255);
            $table->string('collection', 317);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('last_uri', 512)->nullable();
            $table->dateTime('last_repaired_at');

            $table->primary(['repair', 'collection']);
        });

        // A repaired record keeps the repairs that changed it and the record
        // as it is in the repo. Null for every other record, so it costs
        // nothing on the rest of the table.
        $schema->hasColumn('atp_records', 'repair') || $schema->table('atp_records', function (Blueprint $table) {
            $table->json('repair')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('atp_records', fn (Blueprint $table) => $table->dropColumn('repair'));
        $schema->dropIfExists('atp_ingest_repairs');
        $schema->dropIfExists('atp_ingest_rejections');
        $schema->dropIfExists('atp_failed_deliveries');
    }
};
