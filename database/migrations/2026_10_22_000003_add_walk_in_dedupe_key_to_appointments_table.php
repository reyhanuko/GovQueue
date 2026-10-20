<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database-level guard against two walk-in tickets for one client.
 *
 * The walk-in duplicate check is a check-then-act: it looks for a ticket that
 * looks like the one being issued, and if it finds none, it issues one. Two
 * counters pressing the button for the same person at the same moment could
 * both look, both find nothing, and both issue - which is how one resident
 * ends up holding two places in one line.
 *
 * The key is set while the ticket holds a place in the line and cleared when it
 * stops holding one, so a resident who is served and walks in again later the
 * same day is not blocked by their own history.
 *
 * Nullable, and a unique index ignores nulls, so only the live tickets take
 * part in the constraint and no existing row has to be backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('walk_in_dedupe_key', 120)
                ->nullable()
                ->unique()
                ->after('queue_date');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique(['walk_in_dedupe_key']);
            $table->dropColumn('walk_in_dedupe_key');
        });
    }
};
