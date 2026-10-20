<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SocialDept\AtpAppview\Index\ValueHash;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $mysql = in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        // Each account's choices for a reason. A reason with no row takes the
        // defaults: shown in the app, pushed, from everyone.
        $schema->create('atp_notification_preferences', function (Blueprint $table) use ($mysql) {
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

            $ascii($table->string('did', 255));
            $ascii($table->string('reason', 64));
            $table->boolean('in_app')->default(true);
            $table->boolean('push')->default(true);
            // 'everyone', or 'follows' for only the accounts they follow.
            $table->string('from', 16)->default('everyone');

            $table->primary(['did', 'reason']);
        });

        // The actor as atp_record_values hashes a value, so whether the
        // recipient follows them is one indexed lookup.
        $schema->table('atp_notifications', function (Blueprint $table) {
            $table->bigInteger('actor_hash')->nullable();
        });

        $db = DB::connection($this->getConnection());

        $db->table('atp_notifications')->select('actor_did')->distinct()->orderBy('actor_did')->chunk(500, function ($rows) use ($db) {
            foreach ($rows as $row) {
                $db->table('atp_notifications')->where('actor_did', $row->actor_did)->update(['actor_hash' => ValueHash::of($row->actor_did)]);
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('atp_notification_preferences');
        $schema->table('atp_notifications', function (Blueprint $table) {
            $table->dropColumn('actor_hash');
        });
    }
};
