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

        // A verdict per record on the claim it makes, such as owning a
        // website, with the parent it may inherit one from and when to check
        // it again.
        $schema->create('atp_record_claims', function (Blueprint $table) use ($mysql) {
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

            $table->id();
            $ascii($table->string('uri', 512));
            $ascii($table->string('did', 255));
            $ascii($table->string('collection', 317));
            $ascii($table->string('parent', 512))->nullable();
            $table->string('host', 255);
            $ascii($table->char('claim_hash', 40));
            $ascii($table->string('state', 16));
            $ascii($table->string('method', 16));
            $table->unsignedInteger('passes')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->dateTime('failing_since')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('next_check_at');
            $table->string('last_error', 1000)->nullable();

            $table->unique('uri');
            $table->index('next_check_at');
            $table->index('parent');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_record_claims');
    }
};
