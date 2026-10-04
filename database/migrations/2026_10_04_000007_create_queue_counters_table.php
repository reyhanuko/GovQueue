<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gap-free sequential queue numbers per window per day.
     *
     * A dedicated counter row (rather than COUNT(*)+1 or MAX(queue_number)) is
     * what makes queue numbers safe under concurrent bookings: two clients
     * booking the last place in the same slot both lock this row, so the second
     * one blocks and then reads the already-incremented value. Deriving the
     * number from a MAX() scan would let both transactions read the same value
     * and collide.
     */
    public function up(): void
    {
        Schema::create('queue_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_window_id')->constrained()->cascadeOnDelete();
            $table->date('queue_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['service_window_id', 'queue_date'], 'queue_counters_window_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_counters');
    }
};
