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

        // What viewers did with feed items: asked for more or less, opened,
        // saw. Private, kept for the feeds to read, pruned after a while.
        $schema->create('atp_feed_interactions', function (Blueprint $table) use ($mysql) {
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

            $table->id();
            $ascii($table->string('viewer_did', 255));
            $ascii($table->string('feed', 512));
            $ascii($table->string('item', 512));
            $ascii($table->string('event', 64));
            $table->string('feed_context', 2000)->nullable();
            $table->string('req_id', 100)->nullable();
            $table->dateTime('created_at');

            $table->index(['viewer_did', 'feed', 'event', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_feed_interactions');
    }
};
