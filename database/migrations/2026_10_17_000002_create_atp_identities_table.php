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
        Schema::connection($this->getConnection())->create('atp_identities', function (Blueprint $table) {
            $table->string('did', 255)->primary();
            $table->string('handle', 253)->nullable()->index();
            $table->boolean('active')->default(true);
            $table->string('status', 64)->nullable();
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_identities');
    }
};
