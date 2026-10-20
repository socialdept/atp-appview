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

        // Each browser an account turned push on in: where its push service
        // takes messages, and the keys to encrypt them with.
        $schema->create('atp_push_subscriptions', function (Blueprint $table) use ($mysql) {
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

            $table->id();
            $ascii($table->string('did', 255));
            $table->text('endpoint');
            // The endpoint can be long, so it is unique by its hash.
            $ascii($table->string('endpoint_hash', 40))->unique();
            $ascii($table->string('p256dh', 128));
            $ascii($table->string('auth', 64));
            $table->string('user_agent', 512)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('used_at')->nullable();

            $table->index('did');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_push_subscriptions');
    }
};
