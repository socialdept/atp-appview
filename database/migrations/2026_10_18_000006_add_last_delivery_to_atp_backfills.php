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
        // When the backfill last received any record. A repo counts as
        // delivered at its first record, so this is what says Tap has stopped.
        Schema::connection($this->getConnection())->table('atp_backfills', function (Blueprint $table) {
            $table->dateTime('last_delivery_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('atp_backfills', function (Blueprint $table) {
            $table->dropColumn('last_delivery_at');
        });
    }
};
