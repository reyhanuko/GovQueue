<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profile-level mirror of the RA 9994 senior-citizen entitlement.
     *
     * This is derived from birthdate by ClientProfileService and is stored so
     * the booking form and the client's profile page can show it without
     * recomputing on every render. PWD and pregnant claims are deliberately NOT
     * stored here: pregnancy is time-bound and PWD status is never verified by
     * the system, so both are claimed per appointment.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('claims_senior_citizen')->default(false)->after('birthdate')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('claims_senior_citizen');
        });
    }
};
