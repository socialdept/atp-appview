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
        $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

        // Who may read a followed space: an account the space's authority
        // issued a credential to through the AppView, until that credential
        // expires. Renewed while the account uses the space.
        $schema->create('atp_space_grants', function (Blueprint $table) use ($ascii) {
            $table->id();
            $ascii($table->string('space', 512));
            $ascii($table->string('did', 255));
            $table->dateTime('expires_at')->index();
            $table->timestamps();

            $table->unique(['space', 'did']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_space_grants');
    }
};
