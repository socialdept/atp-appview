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
        // What appview:stats last counted, per collection and overall (*).
        Schema::connection($this->getConnection())->create('atp_index_stats', function (Blueprint $table) {
            $table->string('collection', 317)->primary();
            $table->unsignedBigInteger('records');
            $table->unsignedBigInteger('hidden');
            $table->unsignedBigInteger('tombstones');
            $table->unsignedBigInteger('repos');
            $table->dateTime('counted_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_index_stats');
    }
};
