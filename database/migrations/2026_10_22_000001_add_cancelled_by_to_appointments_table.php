<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who closed a booking.
 *
 * "Cancelled" said that a booking would not happen, but not who made it
 * happen: the client releasing a booking they cannot come to, or the office
 * closing a booking it will not serve. Those are different events, and the
 * client is owed the difference - a client who did not cancel their booking
 * should not read "Cancelled" as though they had.
 *
 * So the cancelling user is recorded, and the client is told who did it. The
 * column is null on rows cancelled before this change, and on a walk-in
 * cancelled before it existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('cancelled_by')
                ->nullable()
                ->after('cancelled_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
        });
    }
};
