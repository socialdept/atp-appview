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

        // One row per recipient for each record that notifies them, kept as
        // records are indexed. Whether its record is still visible is read
        // from atp_records, so lifting a moderation action brings it back.
        $schema->create('atp_notifications', function (Blueprint $table) use ($mysql) {
            // ASCII by definition, as on atp_record_refs, to keep MySQL's
            // indexes within InnoDB's key limit.
            $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

            $table->id();
            $ascii($table->string('recipient_did', 255));
            $ascii($table->string('reason', 64));
            // What was acted on: a record's uri, or the recipient's DID.
            $ascii($table->string('subject', 512));
            // A hash of the reason and subject, or of the reason and the
            // notifying record for a separate reason.
            $ascii($table->string('group_key', 40));
            $ascii($table->string('actor_did', 255));
            $ascii($table->string('source_uri', 512));
            $table->dateTime('indexed_at');

            $table->unique(['source_uri', 'recipient_did', 'reason', 'subject'], 'atp_notifications_source');
            // A recipient's newest, one at a time or by group.
            $table->index(['recipient_did', 'id']);
            $table->index(['recipient_did', 'group_key', 'id']);
        });

        // When each recipient last looked: newer notifications are unread.
        $schema->create('atp_notification_seen', function (Blueprint $table) use ($mysql) {
            $did = $table->string('did', 255)->primary();

            if ($mysql) {
                $did->charset('ascii')->collation('ascii_bin');
            }

            $table->dateTime('seen_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('atp_notification_seen');
        $schema->dropIfExists('atp_notifications');
    }
};
