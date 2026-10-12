<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that an administrator set a slot's capacity by hand.
 *
 * Without this, the nightly generator cannot tell a deliberate override from a
 * stale default, so it overwrote both. `govqueue:generate-slots --days=45` runs
 * at 01:30 and rewrote every future slot's capacity back to its template value -
 * meaning an administrator who added places for tomorrow was told "Capacity
 * updated" and the change was gone by morning, with nothing in the log.
 *
 * A boolean rather than a nullable capacity shadow column, because the rule that
 * matters is only "did a human decide this one?". Storing the value the human
 * chose would additionally need the decision itself audited, and the audit trail
 * already records before/after on every change.
 *
 * Default false, which is the safe direction: a slot that was never touched keeps
 * following its template, exactly as before this column existed.
 *
 * Reversible: drops only the column it added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_slots', function (Blueprint $table) {
            $table->boolean('capacity_is_overridden')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('time_slots', function (Blueprint $table) {
            $table->dropColumn('capacity_is_overridden');
        });
    }
};
