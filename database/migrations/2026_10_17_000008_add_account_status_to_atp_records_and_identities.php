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

        $schema->table('atp_records', function (Blueprint $table) {
            // Set while the repo's account is deactivated, taken down or
            // suspended. Queries skip hidden records, and they come back
            // when the account does.
            $table->timestamp('hidden_at')->nullable();
        });

        $schema->table('atp_identities', function (Blueprint $table) {
            // When the account stopped being active, so its records can be
            // tombstoned once it has been gone long enough.
            $table->timestamp('inactive_since')->nullable()->index();
        });

        // Accounts already inactive get what their status means now: a
        // deleted account's records are tombstoned, keeping their revisions,
        // and any other inactive account's are hidden.
        $db = DB::connection($this->getConnection());
        $now = now('UTC')->format('Y-m-d H:i:s');

        $db->table('atp_identities')->where('active', false)->update(['inactive_since' => $db->raw('updated_at')]);

        $db->table('atp_identities')->where('active', false)->orderBy('did')->select(['did', 'status'])
            ->chunk(500, function ($identities) use ($db, $now) {
                $deleted = $identities->where('status', 'deleted')->pluck('did')->all();
                $hidden = $identities->where('status', '!=', 'deleted')->pluck('did')->all();
                $live = fn (array $dids) => $db->table('atp_records')->whereIn('did', $dids)->whereNull('deleted_at');

                if ($deleted !== []) {
                    $live($deleted)->update(['deleted_at' => $now, 'record' => null, 'cid' => null]);
                }

                if ($hidden !== []) {
                    $live($hidden)->update(['hidden_at' => $now]);
                }
            });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->table('atp_records', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });

        $schema->table('atp_identities', function (Blueprint $table) {
            $table->dropIndex(['inactive_since']);
            $table->dropColumn('inactive_since');
        });
    }
};
