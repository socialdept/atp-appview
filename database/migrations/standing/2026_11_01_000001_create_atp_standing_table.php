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

        // Facts about accounts and records: a metric's value, the result of
        // verification, or an account's score, each with when it goes stale.
        $schema->create('atp_standing', function (Blueprint $table) use ($mysql) {
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

            $table->id();
            $ascii($table->string('subject', 512));
            $ascii($table->string('kind', 64));
            $table->double('value')->nullable();
            $table->json('detail')->nullable();
            $table->dateTime('computed_at');
            $table->dateTime('stale_at');

            $table->unique(['subject', 'kind']);
            $table->index(['kind', 'value']);
            $table->index('stale_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_standing');
    }
};
