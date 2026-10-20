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
        // A recipient's newest by time, as lists and groups are ordered.
        Schema::connection($this->getConnection())->table('atp_notifications', function (Blueprint $table) {
            $table->index(['recipient_did', 'indexed_at', 'id'], 'atp_notifications_recipient_time');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('atp_notifications', function (Blueprint $table) {
            $table->dropIndex('atp_notifications_recipient_time');
        });
    }
};
