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

        // Accounts each viewer muted. Private, so kept here and never written
        // to a repo.
        $schema->create('atp_mutes', function (Blueprint $table) use ($mysql) {
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

            $ascii($table->string('did', 255));
            $ascii($table->string('subject', 255));
            $table->dateTime('created_at');

            $table->primary(['did', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_mutes');
    }
};
