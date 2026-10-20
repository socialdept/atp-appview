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
     * Installs from before moderation was a feature made these tables in a
     * core migration, so each is made only when missing.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        // The labelers Tack follows, with the declaration each publishes:
        // its label values, their definitions and default settings.
        if (! $schema->hasTable('atp_labelers')) {
            $schema->create('atp_labelers', function (Blueprint $table) {
                $table->string('did', 255)->primary();
                $table->string('endpoint', 512)->nullable();
                $table->json('declaration')->nullable();
                $table->timestamps();
            });
        }

        // The labels in force on accounts and records the index knows: the
        // newest per labeler, subject and value. Self-labels a record carries
        // are here too, with the author as their source.
        if (! $schema->hasTable('atp_labels')) {
            $schema->create('atp_labels', function (Blueprint $table) {
                $table->id();
                $table->string('src', 255);
                $table->string('uri', 512);
                // The account the label is about: the uri for an account label,
                // the repo of the record otherwise.
                $table->string('subject', 255);
                $table->string('cid', 255)->nullable();
                $table->string('val', 128);
                $table->string('cts', 32);
                $table->dateTime('exp')->nullable();
                $table->boolean('verified')->default(false);
                $table->boolean('self')->default(false);

                $table->unique(['src', 'uri', 'val']);
                $table->index('subject');
                $table->index('uri');
                $table->index(['src', 'val']);
            });
        }

        // What the operator decided a label does, overriding config and the
        // labeler's declaration, for content and for accounts separately.
        // labeler is a DID, or * for any labeler.
        if (! $schema->hasTable('atp_label_policies')) {
            $schema->create('atp_label_policies', function (Blueprint $table) {
                $table->id();
                $table->string('labeler', 255);
                $table->string('value', 128);
                $table->string('target', 16);
                $table->string('effect', 16);
                $table->timestamps();

                $table->unique(['labeler', 'value', 'target']);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('atp_label_policies');
        $schema->dropIfExists('atp_labels');
        $schema->dropIfExists('atp_labelers');
    }
};
