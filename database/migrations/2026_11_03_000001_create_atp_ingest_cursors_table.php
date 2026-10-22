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
        // Where each websocket source stands, saved with the batch it ends.
        Schema::connection($this->getConnection())->create('atp_ingest_cursors', function (Blueprint $table) {
            $table->string('source', 255)->primary();
            $table->unsignedBigInteger('cursor');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_ingest_cursors');
    }
};
