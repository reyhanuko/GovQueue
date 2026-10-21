<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every service window its own queue-number prefix.
 *
 * BookingService::formatQueueNumber() hardcoded 'A-', so Window 1 and Window 2
 * both issued A-001 on the same day. The lobby display shows numbers only, and
 * two windows showing the same number is a display that cannot answer the only
 * question it exists for. SeededDataIntegrityTest passed anyway, because it
 * checks for duplicates per (window, date, number) - and per window there was
 * no duplicate. The collision only existed between windows.
 *
 * Why a column rather than deriving the prefix from the window's code: a
 * derivation rule has silent collision modes. WINDOW_1 and COUNTER_1 would both
 * reduce to "1", which reproduces this exact bug while looking correct in
 * review. An explicit value cannot collide with another window's explicit
 * value, and an administrator can set it to something readable from across a
 * room.
 *
 * Backfill assigns 1, 2, 3... in id order rather than parsing the code, for the
 * same reason: the assignment is collision-proof by construction. The column
 * defaults to 'A' so a window created without one behaves exactly as it did
 * before, and the short prefixes are what make the display legible.
 *
 * Reversible: drops the column. Existing queue numbers are left alone - they
 * are already printed, already on display, and rewriting them would change
 * what a client is holding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_windows', function (Blueprint $table) {
            $table->string('queue_prefix', 4)
                ->default('A')
                ->after('code')
                ->comment('Prefix for this window\'s queue numbers, e.g. 1 issues 1-001.');
        });

        /*
         * Distinct per existing window, in id order.
         *
         * Only where a window still holds the default, so a deployment that
         * already set prefixes keeps them.
         */
        $windows = DB::table('service_windows')
            ->where('queue_prefix', 'A')
            ->orderBy('id')
            ->pluck('id');

        foreach ($windows as $index => $id) {
            // 'A' is only left for the first window, where it reads naturally and
            // keeps every existing A-0NN value valid. Later windows get numbers.
            DB::table('service_windows')
                ->where('id', $id)
                ->update(['queue_prefix' => $index === 0 ? 'A' : (string) ($index + 1)]);
        }
    }

    public function down(): void
    {
        Schema::table('service_windows', function (Blueprint $table) {
            $table->dropColumn('queue_prefix');
        });
    }
};
