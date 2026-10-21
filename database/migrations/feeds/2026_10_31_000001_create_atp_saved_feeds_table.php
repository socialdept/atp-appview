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
        $mysql = in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        // Each viewer's saved feeds, in their order, and which are pinned:
        // Bluesky's savedFeedsPrefV2, kept by the app, since a PDS keeps only
        // Bluesky's own preferences. Private, never written to a repo.
        $schema->create('atp_saved_feeds', function (Blueprint $table) use ($mysql) {
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

            $table->id();
            $ascii($table->string('viewer_did', 255));
            $ascii($table->string('type', 32));
            $table->string('value', 512);
            $table->boolean('pinned')->default(false);
            $table->unsignedInteger('position');

            $table->unique(['viewer_did', 'type', 'value']);
            $table->index(['viewer_did', 'position']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_saved_feeds');
    }
};
