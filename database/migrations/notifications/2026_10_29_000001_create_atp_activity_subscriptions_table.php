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

        // Who wants to hear when an account writes, and about which kinds of
        // activity. Private, like mutes: never written to a repo.
        $schema->create('atp_activity_subscriptions', function (Blueprint $table) use ($mysql) {
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

            $ascii($table->string('subscriber_did', 255));
            $ascii($table->string('subject_did', 255));
            $table->json('kinds');
            $table->dateTime('created_at');

            $table->primary(['subscriber_did', 'subject_did']);
            $table->index('subject_did');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_activity_subscriptions');
    }
};
