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

        // Published lexicons the AppView follows: the version in use, and a
        // newer one held back when it would break the one in use.
        $schema->create('atp_lexicons', function (Blueprint $table) {
            $table->string('nsid', 317)->primary();
            $table->string('did', 255);
            $table->string('uri', 1024);
            $table->string('cid', 128)->nullable();
            $table->longText('schema');
            $table->unsignedInteger('revision')->default(1);
            $table->string('pending_cid', 128)->nullable();
            $table->longText('pending_schema')->nullable();
            $table->text('pending_issues')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // Every version that was put in use, oldest first.
        $schema->create('atp_lexicon_versions', function (Blueprint $table) {
            $table->id();
            $table->string('nsid', 317)->index();
            $table->unsignedInteger('revision');
            $table->string('cid', 128)->nullable();
            $table->longText('schema');
            $table->boolean('forced')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('atp_lexicon_versions');
        $schema->dropIfExists('atp_lexicons');
    }
};
