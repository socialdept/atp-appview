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

        $schema->create('atp_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8);

            // A DID, or a PDS host pattern where * matches anything.
            $table->string('subject', 255);

            // * for every collection, an exact NSID, or a prefix ending in .*
            // Never null, so the unique index below also covers blanket rules.
            $table->string('collection', 317)->default('*');

            $table->text('note')->nullable();
            $table->unsignedBigInteger('blocked_count')->default(0);
            $table->timestamp('last_blocked_at')->nullable();
            $table->timestamps();

            $table->unique(['subject', 'collection']);
        });

        $schema->table('atp_identities', function (Blueprint $table) {
            // The repo's PDS, resolved from its DID document for host rules.
            $table->string('pds', 512)->nullable();
            $table->timestamp('pds_resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('atp_blocks');

        $schema->table('atp_identities', function (Blueprint $table) {
            $table->dropColumn(['pds', 'pds_resolved_at']);
        });
    }
};
