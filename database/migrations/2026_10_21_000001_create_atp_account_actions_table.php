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
     * The columns account actions keep on core tables. The account actions
     * table moved to the moderation feature's migrations, and this file
     * keeps its name so installs that ran it before do not run it again.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        // Records that need review: shown where asked for directly, left out
        // of discovery. Kept beside moderated_at and hidden_at.
        $schema->table('atp_records', function (Blueprint $table) {
            $table->timestamp('limited_at')->nullable();
        });

        $schema->table('atp_record_values', function (Blueprint $table) {
            $table->boolean('limited')->default(false);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('atp_record_values', fn (Blueprint $table) => $table->dropColumn('limited'));
        $schema->table('atp_records', fn (Blueprint $table) => $table->dropColumn('limited_at'));
    }
};
