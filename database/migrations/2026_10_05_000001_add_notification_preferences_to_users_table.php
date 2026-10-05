<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Per-client notification preferences.
         *
         * Until Sprint 9 every client received email and SMS for every appointment
         * event, decided entirely by office-wide config. A resident with no phone
         * number, no credit, or no interest in texts had no way to opt out, which
         * is both a practical problem and a data-privacy one.
         *
         * Both default to true so existing accounts keep the behaviour they are
         * used to; opting out is a deliberate act by the client.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_email')->default(true)->after('is_active');
            $table->boolean('notify_sms')->default(true)->after('notify_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_email', 'notify_sms']);
        });
    }
};
