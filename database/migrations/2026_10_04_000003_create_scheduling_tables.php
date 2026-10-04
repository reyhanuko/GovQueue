<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weekly recurring schedule template. An administrator defines these;
        // the `govqueue:generate-slots` command expands them into concrete
        // rows in time_slots for a given date range.
        Schema::create('slot_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday .. 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->unsignedSmallInteger('capacity')->default(5);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['service_id', 'day_of_week'], 'slot_templates_service_day_index');
        });

        Schema::create('time_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_window_id')->constrained()->cascadeOnDelete();
            $table->date('slot_date');
            $table->time('start_time');
            $table->time('end_time');
            // Concrete timestamps kept in sync with the date + time columns so
            // queue ordering can sort on a single datetime instead of joining.
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('booked_count')->default(0);
            $table->boolean('is_open')->default(true)->index();
            $table->timestamps();

            $table->unique(['service_id', 'slot_date', 'start_time'], 'time_slots_unique_slot');
            $table->index(['service_window_id', 'slot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_slots');
        Schema::dropIfExists('slot_templates');
    }
};
