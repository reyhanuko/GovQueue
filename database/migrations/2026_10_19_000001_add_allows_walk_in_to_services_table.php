<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a service accepts walk-ins at all.
 *
 * Decision D12: eligibility is per service, not a property of the office.
 *
 * The reasoning is operational, not technical. A high-volume predictable
 * service - Real Estate Tax Payments, Document Pickup - absorbs continuous
 * walk-ins easily. A legally intensive one - Zoning Board Review, Marriage
 * License Application - needs a booked slot, because each takes a clerk's full
 * attention and a document check that cannot be done while the next person is
 * already at the counter.
 *
 * Defaults to true on purpose. Every existing service accepted walk-ins until
 * now, and defaulting the other way would silently switch them off the moment
 * the migration ran - breaking the counter on deployment day, for no reason an
 * administrator chose.
 *
 * Backfilling rather than defaulting in the schema, so the intent is visible to
 * whoever reads the table afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // After the active flag: it is the same kind of column, and this is
            // where an administrator looks for it.
            $table->boolean('allows_walk_in')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('allows_walk_in');
        });
    }
};
