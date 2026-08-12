<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     | WHY: one day belongs to exactly one country — that IS the residency
     | model (see App\Support\ContiguousStays and the write path's
     | Event::firstOrNew(['user_id', 'day'])). Without this index the schema
     | allows a second row for the same (user_id, day), which
     | ContiguousStays::intersectingYear() would then have to arbitrate (the
     | usort tie-break on the title exists for exactly that case).
     |
     | PRODUCTION PRE-CHECK OUTSTANDING — not run by this migration, no access
     | to run it: on PostgreSQL, before deploying this migration, run
     |
     |   SELECT user_id, day, COUNT(*)
     |   FROM events
     |   GROUP BY user_id, day
     |   HAVING COUNT(*) > 1;
     |
     | If this returns any row, the migration WILL fail on deploy (Postgres
     | rejects a unique index over duplicate data) and those rows need
     | resolving first (App\Support\ContiguousStays' tie-break shows which one
     | would have "won" under the old code: highest title, i.e. reverse
     | alphabetical). Local sqlite has no duplicates by construction of the
     | test fixtures — that is not evidence about production, which is why
     | this check is named here instead of assumed away.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unique(['user_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'day']);
        });
    }
};
