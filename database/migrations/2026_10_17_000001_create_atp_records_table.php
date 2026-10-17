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
        Schema::connection($this->getConnection())->create('atp_records', function (Blueprint $table) {
            $table->string('uri', 512)->primary();
            $table->string('did', 255);
            $table->string('collection', 317);
            $table->string('rkey', 512);
            $table->string('cid', 255)->nullable();

            // The repo commit's revision: a 13 character TID whose alphabet
            // is in ASCII order, so byte order is time order. A locale-aware
            // collation could order them differently, and the upsert guard
            // compares them.
            $rev = $table->string('rev', 64);

            match (Schema::connection($this->getConnection())->getConnection()->getDriverName()) {
                'pgsql' => $rev->collation('C'),
                'mysql', 'mariadb' => $rev->collation('utf8mb4_bin'),
                default => null,
            };

            $table->json('record')->nullable();
            $table->timestamp('indexed_at');
            $table->timestamp('deleted_at')->nullable();

            $table->index(['collection', 'indexed_at']);
            $table->index(['did', 'collection']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_records');
    }
};
