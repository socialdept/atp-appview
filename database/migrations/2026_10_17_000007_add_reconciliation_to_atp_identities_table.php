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
        Schema::connection($this->getConnection())->table('atp_identities', function (Blueprint $table) {
            // When appview:reconcile last compared the repo with its PDS, the
            // revision it compared against, and why it failed, if it did.
            $table->timestamp('reconciled_at')->nullable()->index();
            $table->string('reconciled_rev', 32)->nullable();
            $table->text('reconcile_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('atp_identities', function (Blueprint $table) {
            $table->dropIndex(['reconciled_at']);
            $table->dropColumn(['reconciled_at', 'reconciled_rev', 'reconcile_error']);
        });
    }
};
