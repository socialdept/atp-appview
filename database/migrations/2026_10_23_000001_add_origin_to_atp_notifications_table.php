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
        // What made each notification: a configured reason, a notifier or
        // Notifications::send(). Each is kept by its own rules.
        Schema::connection($this->getConnection())->table('atp_notifications', function (Blueprint $table) {
            $table->string('origin', 16)->default('reason');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('atp_notifications', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
