<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The no-show block (decision D6).
 *
 * One column on the client, because the block is a property of the client and
 * has to be readable on every booking screen without counting anything.
 *
 * The count of no-shows that produced the block lives in its own table
 * (no_show_strikes), so the office can see *why* somebody is blocked and
 * staff can see the history rather than a number that appeared from nowhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('booking_blocked_until')
                ->nullable()
                ->after('notify_sms')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['booking_blocked_until']);
            $table->dropColumn('booking_blocked_until');
        });
    }
};
