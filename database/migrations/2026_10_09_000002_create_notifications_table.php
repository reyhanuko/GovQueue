<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table Laravel's database notification driver writes to.
 *
 * GovQueue has been dispatching an AppointmentNotification on every booking
 * since Sprint 6, but this table was never created. The failure was invisible
 * because ClientNotificationService isolates each channel: the exception was
 * caught and the delivery row was marked failed, so bookings kept working while
 * every client silently received nothing in-app. Admin → Notifications was the
 * only place it showed up, as "SQLSTATE[42S02] ... Table 'govqueue.notifications'
 * doesn't exist".
 *
 * This is Laravel's standard schema - the id is a UUID because notifications
 * are polymorphic and an incrementing integer would leak how many messages the
 * system has ever sent.
 *
 * Reversible: drops only the table it created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};