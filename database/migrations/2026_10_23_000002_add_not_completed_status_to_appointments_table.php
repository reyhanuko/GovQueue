<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transaction that was served but could not be completed.
 *
 * A client who came to the counter, was served, and did not have the
 * documents the service needs had no outcome of their own. They were not a
 * no-show - they were there - and they were not completed, so the ticket had
 * to be neither. "Not completed" says what happened, and it is a closed
 * status, because the client is free to book again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('status', [
                'pending', 'confirmed', 'checked_in', 'serving',
                'completed', 'cancelled', 'no_show', 'skipped',
                'not_completed',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('status', [
                'pending', 'confirmed', 'checked_in', 'serving',
                'completed', 'cancelled', 'no_show', 'skipped',
            ])->default('pending')->change();
        });
    }
};
