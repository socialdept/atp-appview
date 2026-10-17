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
     * Listing one repo's records in a collection, newest first, is what a
     * did or actor filter asks for. (did, collection) found the repo but not
     * the order, so the planner walked the whole collection in sort order
     * instead: 70 to 570 ms for a repo with 76,000 records among 220,000.
     * The wider index serves that list directly and still covers every
     * lookup by did, or by did and collection, that the old one did.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->table('atp_records', function (Blueprint $table) {
            $table->index(['did', 'collection', 'sort_at', 'uri']);
            $table->dropIndex(['did', 'collection']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('atp_records', function (Blueprint $table) {
            $table->index(['did', 'collection']);
            $table->dropIndex(['did', 'collection', 'sort_at', 'uri']);
        });
    }
};
