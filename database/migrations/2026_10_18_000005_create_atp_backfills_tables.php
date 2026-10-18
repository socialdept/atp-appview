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

        // A collection's backfill: the repos the relay says hold it, and how
        // it ended.
        $schema->create('atp_backfills', function (Blueprint $table) {
            $table->id();
            $table->string('collection', 317)->index();
            $table->string('source');
            $table->string('status', 16)->default('running')->index();
            $table->text('error')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
        });

        // Each repo in a backfill, how Tap was asked to backfill it, and when
        // its records arrived.
        $schema->create('atp_backfill_repos', function (Blueprint $table) {
            $table->foreignId('backfill_id')->constrained('atp_backfills')->cascadeOnDelete();
            $table->string('did');
            $table->string('method', 8)->nullable();
            $table->unsignedInteger('records')->default(0);
            $table->dateTime('delivered_at')->nullable();
            $table->text('error')->nullable();
            $table->primary(['backfill_id', 'did']);
            $table->index(['backfill_id', 'delivered_at']);
        });

        // Every collection the index has been told to ingest, so a new one is
        // backfilled once. baseline marks those already holding records when
        // tracking began.
        $schema->create('atp_ingest_collections', function (Blueprint $table) {
            $table->string('collection', 317)->primary();
            $table->dateTime('first_seen_at');
            $table->dateTime('removed_at')->nullable();
            $table->foreignId('backfill_id')->nullable()->constrained('atp_backfills')->nullOnDelete();
            $table->boolean('baseline')->default(false);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('atp_ingest_collections');
        $schema->dropIfExists('atp_backfill_repos');
        $schema->dropIfExists('atp_backfills');
    }
};
