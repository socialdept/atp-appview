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
        $schema = Schema::connection($this->getConnection());

        // What was done to an account, by an operator or because of a label,
        // and when it was lifted. Rows are never deleted, so this is also the
        // record of moderation.
        $schema->create('atp_account_actions', function (Blueprint $table) {
            $table->id();
            $table->string('did', 255);
            // ban, review or takedown.
            $table->string('action', 16);
            // manual, or label for one a label applied.
            $table->string('source', 16)->default('manual');
            $table->text('reason')->nullable();
            $table->string('label_src', 255)->nullable();
            $table->string('label_val', 128)->nullable();
            $table->string('actor', 255)->nullable();
            // The PDS a takedown was sent to.
            $table->string('pds', 512)->nullable();
            // When a ban's records are pruned, unless the pruning is cancelled.
            $table->dateTime('prune_at')->nullable();
            $table->boolean('prune_cancelled')->default(false);
            $table->dateTime('pruned_at')->nullable();
            $table->timestamps();
            $table->dateTime('lifted_at')->nullable();
            $table->string('lifted_by', 255)->nullable();

            $table->index(['did', 'action', 'lifted_at']);
            $table->index(['action', 'lifted_at']);
        });

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
        $schema->dropIfExists('atp_account_actions');
    }
};
