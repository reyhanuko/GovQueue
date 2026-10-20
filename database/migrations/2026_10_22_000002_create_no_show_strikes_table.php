<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per unexcused no-show (decision D6).
 *
 * A record rather than a counter on the user, because the office has to be
 * able to answer "why is this resident blocked?" and a number on a row cannot.
 * It also means the block is auditable: every strike points at the appointment
 * that produced it.
 *
 * The count that matters is the number of strikes since the client's last
 * block, so a resident who has served their fortnight starts again rather than
 * being blocked forever for three mistakes spread over a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('no_show_strikes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('no_show_strikes');
    }
};
