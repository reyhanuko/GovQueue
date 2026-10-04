<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 24)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('time_slot_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_window_id')->constrained()->restrictOnDelete();

            $table->enum('status', [
                'pending', 'confirmed', 'checked_in', 'serving',
                'completed', 'cancelled', 'no_show', 'skipped',
            ])->default('pending')->index();

            $table->enum('source', ['online', 'walk_in'])->default('online')->index();

            // Denormalised copy of the booked slot's start time so queue ordering
            // and reporting never need to join time_slots.
            $table->dateTime('scheduled_at')->nullable()->index();

            // Queue number is unique per window + queue day, so "A-014" is
            // meaningful on the public display without being globally unique.
            $table->string('queue_number', 12)->nullable();
            $table->date('queue_date')->nullable()->index();

            // Priority claim as declared by the client at booking time, plus
            // the staff verification that actually grants precedence.
            $table->boolean('claims_senior_citizen')->default(false);
            $table->boolean('claims_pwd')->default(false);
            $table->boolean('claims_pregnant')->default(false);
            $table->string('priority_reference', 120)->nullable();
            $table->timestamp('priority_verified_at')->nullable();
            $table->foreignId('priority_verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('client_note')->nullable();
            $table->text('staff_note')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('serving_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();

            $table->unsignedTinyInteger('skip_count')->default(0);
            $table->unsignedSmallInteger('estimated_wait_minutes')->nullable();

            $table->timestamps();

            $table->index(['service_window_id', 'queue_date', 'status'], 'appts_window_queue_status_index');
            $table->index(['time_slot_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('queue_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['appointment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_status_histories');
        Schema::dropIfExists('appointments');
    }
};
