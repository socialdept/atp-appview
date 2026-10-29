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
        $driver = $schema->getConnection()->getDriverName();
        $mysql = in_array($driver, ['mysql', 'mariadb'], true);
        $ascii = fn ($column) => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

        // Revisions are TIDs, compared by the upsert guard, so byte order
        // must be time order, as in atp_records.
        $binary = fn ($column) => match ($driver) {
            'pgsql' => $column->collation('C'),
            'mysql', 'mariadb' => $column->charset('ascii')->collation('ascii_bin'),
            default => $column,
        };

        // The spaces the app follows, with where their sync stands: the
        // authority's spaceRev cursor, whose stored credential syncs it, when
        // the notification registration lapses, and why it last failed.
        $schema->create('atp_spaces', function (Blueprint $table) use ($ascii, $binary) {
            $table->id();
            $ascii($table->string('space', 512))->unique();
            $ascii($table->string('authority', 255));
            $ascii($table->string('type', 317));
            $ascii($table->string('skey', 512));
            $binary($table->string('space_rev', 64)->nullable());
            $ascii($table->string('credential_did', 255)->nullable());
            $table->dateTime('notify_expires_at')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->dateTime('stale_at')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();
        });

        // Each member's repo in a followed space: the revision synced to and
        // the running set hash at that revision, base64 encoded, so the next
        // sync can check its copy against the repo's commit.
        $schema->create('atp_space_repos', function (Blueprint $table) use ($ascii, $binary) {
            $table->id();
            $ascii($table->string('space', 512));
            $ascii($table->string('did', 255));
            $binary($table->string('repo_rev', 64)->nullable());
            $table->text('state')->nullable();
            $table->dateTime('synced_at')->nullable();

            $table->unique(['space', 'did']);
        });

        // Records in followed spaces, kept apart from atp_records so no
        // reader of the public index can ever see one. Columns mean what
        // they mean there, and the uri is the space record URI.
        $schema->create('atp_space_records', function (Blueprint $table) use ($ascii, $binary) {
            $ascii($table->string('uri', 1024))->primary();
            $ascii($table->string('space', 512));
            $ascii($table->string('did', 255));
            $ascii($table->string('collection', 317));
            $ascii($table->string('rkey', 512));
            $ascii($table->string('cid', 255)->nullable());
            $binary($table->string('rev', 64));
            $table->jsonb('record')->nullable();
            $table->jsonb('repair')->nullable();
            $table->dateTime('indexed_at');
            $table->dateTime('sort_at', 6)->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['space', 'collection', 'sort_at']);
            $table->index(['space', 'did']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('atp_space_records');
        $schema->dropIfExists('atp_space_repos');
        $schema->dropIfExists('atp_spaces');
    }
};
