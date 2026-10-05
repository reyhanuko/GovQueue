<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The reminder command.
 */
class SendAppointmentRemindersTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'govqueue.notifications.enabled' => true,
            'govqueue.notifications.email' => true,
            'govqueue.notifications.sms' => false,
            'govqueue.notifications.in_app' => false,
            'govqueue.notifications.reminder_hours_before' => [24, 1],
        ]);

        $this->window = ServiceWindow::factory()->create();
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
    }

    private function appointmentAt(Carbon $when, AppointmentStatus|string $status = AppointmentStatus::Confirmed): Appointment
    {
        $status = $status instanceof AppointmentStatus ? $status : AppointmentStatus::from($status);
        $user = User::factory()->client()->create();

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $when->toDateString(),
            'start_time' => $when->format('H:i:s'),
            'end_time' => $when->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $when->toDateTimeString(),
            'end_at' => $when->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        return Appointment::create([
            'reference_number' => 'GQ-R-'.uniqid(),
            'user_id' => $user->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'status' => $status,
            'source' => 'online',
            'scheduled_at' => $when,
            'queue_number' => 'A-001',
            'queue_date' => $when->toDateString(),
        ]);
    }

    public function test_a_reminder_is_sent_for_an_appointment_24_hours_out(): void
    {
        Mail::fake();

        $appointment = $this->appointmentAt(now()->addHours(24));

        $this->artisan('govqueue:send-reminders')->assertSuccessful();

        Mail::assertQueued(
            AppointmentReminderMail::class,
            fn ($mail) => $mail->appointment->is($appointment) && $mail->hoursBefore === 24,
        );

        $this->assertTrue($appointment->fresh()->hasSentReminder(24));
    }

    public function test_a_reminder_is_not_sent_twice_for_the_same_offset(): void
    {
        Mail::fake();

        $this->appointmentAt(now()->addHours(24));

        $this->artisan('govqueue:send-reminders')->assertSuccessful();
        $this->artisan('govqueue:send-reminders')->assertSuccessful();

        Mail::assertQueuedCount(1);
    }

    public function test_both_offsets_can_be_sent_for_one_appointment(): void
    {
        Mail::fake();

        $appointment = $this->appointmentAt(now()->addHours(24));

        $this->artisan('govqueue:send-reminders')->assertSuccessful();
        $this->assertSame([24], $appointment->fresh()->reminderOffsets());

        // A day later the 1-hour reminder becomes due.
        $this->travelTo(now()->addHours(23));
        $this->artisan('govqueue:send-reminders --hours=1')->assertSuccessful();

        $this->assertSame([1, 24], $appointment->fresh()->reminderOffsets());
    }

    public function test_appointments_outside_the_window_are_not_reminded(): void
    {
        Mail::fake();

        // 5 hours out: neither the 24h nor the 1h offset applies.
        $this->appointmentAt(now()->addHours(5));

        $this->artisan('govqueue:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_cancelled_appointments_are_not_reminded(): void
    {
        Mail::fake();

        $this->appointmentAt(now()->addHours(24), AppointmentStatus::Cancelled);

        $this->artisan('govqueue:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_a_checked_in_appointment_is_not_reminded(): void
    {
        Mail::fake();

        // The client is already at the counter; a reminder would be nonsense.
        $this->appointmentAt(now()->addHours(24), AppointmentStatus::CheckedIn);

        $this->artisan('govqueue:send-reminders')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_dry_run_reports_without_sending(): void
    {
        Mail::fake();

        $appointment = $this->appointmentAt(now()->addHours(24));

        $this->artisan('govqueue:send-reminders --dry-run')
            ->expectsOutputToContain('Would send 1 reminder')
            ->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertFalse($appointment->fresh()->hasSentReminder(24),
            'A dry run must not mark the offset as sent.');
    }

    public function test_nothing_happens_when_notifications_are_disabled(): void
    {
        config(['govqueue.notifications.enabled' => false]);
        Mail::fake();

        $this->appointmentAt(now()->addHours(24));

        $this->artisan('govqueue:send-reminders')
            ->expectsOutputToContain('Notifications are disabled')
            ->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_marking_a_reminder_is_idempotent_on_the_model(): void
    {
        $appointment = $this->appointmentAt(now()->addHours(24));

        $this->assertFalse($appointment->hasSentReminder(24));

        $appointment->markReminderSent(24);
        $this->assertTrue($appointment->fresh()->hasSentReminder(24));

        $appointment->fresh()->markReminderSent(24);
        $this->assertSame([24], $appointment->fresh()->reminderOffsets(),
            'Marking the same offset twice must not duplicate it.');
    }

    public function test_reminder_offsets_are_stored_sorted(): void
    {
        $appointment = $this->appointmentAt(now()->addHours(24));

        $appointment->markReminderSent(24);
        $appointment->markReminderSent(1);
        $appointment->markReminderSent(48);

        $this->assertSame([1, 24, 48], $appointment->fresh()->reminderOffsets());
    }

    public function test_a_reminder_records_a_delivery_log_row(): void
    {
        Mail::fake();

        $appointment = $this->appointmentAt(now()->addHours(24));

        $this->artisan('govqueue:send-reminders')->assertSuccessful();

        $this->assertSame(1, NotificationLog::where('appointment_id', $appointment->id)->count());
    }

    public function test_the_schedule_runs_the_reminder_command_hourly(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command ?? '');

        $this->assertTrue(
            $events->contains(fn ($c) => str_contains($c, 'govqueue:send-reminders')),
            'The reminder command must be scheduled or reminders never send.',
        );
    }
}
