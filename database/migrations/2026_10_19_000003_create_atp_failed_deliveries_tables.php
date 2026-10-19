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

        // Events the handlers could not take, kept with what failed, so the
        // rest of their batch could be acknowledged and ingest moves on.
        $schema->create('atp_failed_deliveries', function (Blueprint $table) {
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
        // with the latest record, so the table stays small.
        $schema->create('atp_ingest_rejections', function (Blueprint $table) {
            $table->string('hook', 255);
            $table->string('collection', 317);
            $table->string('reason', 255);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('last_uri', 512)->nullable();
            $table->dateTime('last_rejected_at');

            $table->primary(['hook', 'collection', 'reason']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('atp_ingest_rejections');
        $schema->dropIfExists('atp_failed_deliveries');
    }
};
