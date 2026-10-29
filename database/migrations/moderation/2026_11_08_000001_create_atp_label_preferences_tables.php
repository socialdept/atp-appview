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

        // What each viewer chose to see for a label value, from one labeler
        // or any (*): ignore, warn or hide. Private, like mutes. On MySQL the
        // key is 255 + 255 + 128 * 4 = 1022 bytes.
        $schema->create('atp_label_preferences', function (Blueprint $table) use ($ascii) {
            $ascii($table->string('did', 255));
            $ascii($table->string('labeler', 255));
            $table->string('value', 128);
            $table->string('visibility', 16);
            $table->dateTime('updated_at');

            $table->primary(['did', 'labeler', 'value']);
        });

        // Whether each viewer sees adult content at all. A viewer with no row
        // takes atp-appview.labels.adult_content.
        $schema->create('atp_label_settings', function (Blueprint $table) use ($ascii) {
            $ascii($table->string('did', 255))->primary();
            $table->boolean('adult_content');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('atp_label_settings');
        $schema->dropIfExists('atp_label_preferences');
    }
};
