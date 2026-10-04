<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per finished client transaction, captured by staff.
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 24)->unique();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('details')->nullable();
            $table->decimal('fee_paid', 10, 2)->default(0);
            $table->enum('status', ['completed', 'cancelled', 'failed'])->default('completed')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'completed_at']);
            $table->index('handled_by');
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('channel', ['email', 'sms', 'in_app']);
            $table->string('type', 40);
            $table->string('destination', 160)->nullable();
            $table->string('subject', 160)->nullable();
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['appointment_id', 'type']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60)->index();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('transactions');
    }
};
