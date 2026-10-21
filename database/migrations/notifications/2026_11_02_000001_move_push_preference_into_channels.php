<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Channels are the app's, so each is a key here rather than a column.
        $schema->table('atp_notification_preferences', function (Blueprint $table) {
            $table->json('channels')->nullable();
        });

        DB::connection($this->getConnection())->table('atp_notification_preferences')->where('push', false)->update(['channels' => json_encode(['push' => false])]);

        $schema->table('atp_notification_preferences', function (Blueprint $table) {
            $table->dropColumn('push');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->table('atp_notification_preferences', function (Blueprint $table) {
            $table->boolean('push')->default(true);
        });

        $db = DB::connection($this->getConnection());

        foreach ($db->table('atp_notification_preferences')->whereNotNull('channels')->get() as $row) {
            if ((json_decode((string) $row->channels, true)['push'] ?? true) === false) {
                $db->table('atp_notification_preferences')->where('did', $row->did)->where('reason', $row->reason)->update(['push' => false]);
            }
        }

        $schema->table('atp_notification_preferences', function (Blueprint $table) {
            $table->dropColumn('channels');
        });
    }
};
