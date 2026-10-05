<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reminder offsets already sent for an appointment, e.g. [24, 1].
     *
     * Stored on the appointment rather than inferred from notification_logs so
     * that "have we already sent the 24-hour reminder?" is a single indexed
     * lookup, and so the notification log stays a record of real deliveries
     * rather than also carrying bookkeeping rows.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->json('reminders_sent')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reminders_sent');
        });
    }
};
