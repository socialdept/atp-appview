<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    /**
     * The columns moderation keeps on core tables. The label tables
     * themselves moved to the moderation feature's migrations, and this file
     * keeps its name so installs that ran it before do not run it again.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Records hidden by moderation, a label or a ban, kept apart from
        // hidden_at, which follows the account's status, so lifting one never
        // shows what the other hides.
        $schema->table('atp_records', function (Blueprint $table) {
            $table->timestamp('moderated_at')->nullable();
        });

        // When the account was registered with Tack, so its labels are fetched.
        $schema->table('atp_identities', function (Blueprint $table) {
            $table->timestamp('labels_synced_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('atp_identities', function (Blueprint $table) {
            $table->dropIndex(['labels_synced_at']);
            $table->dropColumn('labels_synced_at');
        });
        $schema->table('atp_records', fn (Blueprint $table) => $table->dropColumn('moderated_at'));
    }
};
