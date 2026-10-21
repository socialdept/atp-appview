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

        // Words and phrases each viewer muted, private like account mutes:
        // records holding all of an entry's words, in the text sources it
        // names, are left out of what that viewer reads.
        $schema->create('atp_muted_words', function (Blueprint $table) use ($mysql) {
            $did = $table->string('did', 255);

            if ($mysql) {
                $did->charset('ascii')->collation('ascii_bin');
            }

            $table->id();
            // As the viewer typed it, and as the words it is matched by.
            $table->string('value', 255);
            $table->json('words');
            // The sources from atp-appview.graph.muted_words it applies to,
            // or null for all of them.
            $table->json('sources')->nullable();
            // Records by accounts the viewer follows still show.
            $table->boolean('exclude_following')->default(false);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('created_at');

            $table->index('did');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('atp_muted_words');
    }
};
