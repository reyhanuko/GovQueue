<?php

namespace Tests\Feature;

use App\Mail\AppointmentCancelledMail;
use App\Mail\AppointmentConfirmedMail;
use App\Mail\AppointmentRescheduledMail;
use App\Models\Appointment;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ClientNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The booking lifecycle must notify the client without the notification ever
 * being able to break the booking.
 */
class BookingTriggersNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'govqueue.notifications.enabled' => true,
            'govqueue.notifications.email' => true,
            'govqueue.notifications.sms' => false,
            'govqueue.notifications.in_app' => false,
        ]);

        $window = ServiceWindow::factory()->create();
        $this->service = Service::factory()->create([
            'service_window_id' => $window->id,
            'code' => 'CERT-IND',
        ]);
        $this->client = User::factory()->client()->create([
            'phone' => '09171234567',
            'email' => 'ana@example.test',
        ]);
    }

    private function slot(?string $date = null, ?string $time = null): TimeSlot
    {
        $this->slotCounter++;
        $start = $date
            ? Carbon::parse($date)->setTimeFromTimeString($time ?? '09:00')
            : now()->addDay()->setTime(9, 0)->addMinutes($this->slotCounter * 30);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->service->service_window_id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 5,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    private function bookViaService(TimeSlot $slot): Appointment
    {
        return app(BookingService::class)->book($this->client, $this->service, $slot->id);
    }

    public function test_booking_sends_a_confirmation(): void
    {
        Mail::fake();

        $appointment = $this->bookViaService($this->slot());

        Mail::assertQueued(AppointmentConfirmedMail::class, fn ($m) => $m->appointment->is($appointment));
        $this->assertSame(1, NotificationLog::where('type', ClientNotificationService::CONFIRMED)->count());
    }

    public function test_rescheduling_sends_a_move_notice(): void
    {
        Mail::fake();

        $first = $this->slot();
        $appointment = $this->bookViaService($first);

        $second = $this->slot(now()->addDays(3)->toDateString(), '11:00');

        app(BookingService::class)->reschedule($appointment, $second->id);

        Mail::assertQueued(AppointmentRescheduledMail::class);
        $this->assertSame(1, NotificationLog::where('type', ClientNotificationService::RESCHEDULED)->count());
    }

    public function test_cancelling_sends_a_cancellation_notice(): void
    {
        Mail::fake();

        $appointment = $this->bookViaService($this->slot());

        app(BookingService::class)->cancel($appointment, 'Client request');

        Mail::assertQueued(AppointmentCancelledMail::class);
        $this->assertSame(1, NotificationLog::where('type', ClientNotificationService::CANCELLED)->count());
    }

    public function test_cancelling_twice_does_not_send_two_notices(): void
    {
        Mail::fake();

        $appointment = $this->bookViaService($this->slot());
        $booking = app(BookingService::class);

        $booking->cancel($appointment, 'First');
        $booking->cancel($appointment->fresh(), 'Again');

        Mail::assertQueuedCount(2); // one confirmation, one cancellation
        $this->assertSame(1, NotificationLog::where('type', ClientNotificationService::CANCELLED)->count());
    }

    public function test_no_confirmation_is_sent_when_notifications_are_disabled(): void
    {
        config(['govqueue.notifications.enabled' => false]);
        Mail::fake();

        $this->bookViaService($this->slot());

        Mail::assertNothingQueued();
        $this->assertSame(0, NotificationLog::count());
    }

    public function test_the_booking_still_succeeds_when_the_mail_transport_broken(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        $slot = $this->slot();

        // Must not throw: the client still gets their booking.
        $appointment = $this->bookViaService($slot);

        $this->assertSame('confirmed', $appointment->status->value);
        $this->assertSame(1, $slot->fresh()->booked_count,
            'A broken mail server must not roll back the booking.');
    }

    public function test_sms_and_email_are_both_attempted_when_enabled(): void
    {
        config(['govqueue.notifications.sms' => true]);
        Mail::fake();

        $this->bookViaService($this->slot());

        $channels = NotificationLog::pluck('channel')->map(fn ($c) => $c->value)->all();

        $this->assertContains('email', $channels);
        $this->assertContains('sms', $channels);
    }
}
