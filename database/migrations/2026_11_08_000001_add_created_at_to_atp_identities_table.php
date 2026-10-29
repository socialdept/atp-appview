<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('atp-appview.index.connection');
    }

    /**
     * When the index first saw each repo, for questions like how many repos
     * it gained this week. Every write that adds an identity sets it, and
     * none changes it after.
     *
     * Repos already indexed get the earliest time the index holds for them:
     * their oldest record's indexed_at, or the identity's updated_at when
     * that is earlier or they have no records. Both come after the repo was
     * first seen, so the backfill is never earlier than the truth, only
     * sometimes later. The column stays nullable, since adding a NOT NULL
     * column to a filled table needs a default, which SQLite cannot take
     * from the clock.
     *
     * MySQL does not roll back DDL, so each step checks whether it already
     * ran, and the backfill only fills what is still empty.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $connection = $schema->getConnection();

        if (! $schema->hasColumn('atp_identities', 'created_at')) {
            $schema->table('atp_identities', fn (Blueprint $table) => $table->dateTime('created_at')->nullable());
        }

        $connection->table('atp_identities')->whereNull('created_at')->update(['created_at' => $connection->raw('updated_at')]);

        $connection->statement(<<<'SQL'
            update atp_identities
            set created_at = (select min(atp_records.indexed_at) from atp_records where atp_records.did = atp_identities.did)
            where exists (
                select 1 from atp_records
                where atp_records.did = atp_identities.did and atp_records.indexed_at < atp_identities.created_at
            )
            SQL);

        if (! $schema->hasIndex('atp_identities', ['created_at'])) {
            $schema->table('atp_identities', fn (Blueprint $table) => $table->index('created_at'));
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasIndex('atp_identities', ['created_at'])) {
            $schema->table('atp_identities', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        }

        if ($schema->hasColumn('atp_identities', 'created_at')) {
            $schema->table('atp_identities', fn (Blueprint $table) => $table->dropColumn('created_at'));
        }
    }
};
