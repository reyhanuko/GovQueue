<?php

namespace Tests\Feature;

use App\Exceptions\SlotUnavailableException;
use App\Mail\AppointmentCancelledMail;
use App\Mail\AppointmentConfirmedMail;
use App\Mail\AppointmentRescheduledMail;
use App\Models\Appointment;
use App\Models\Holiday;
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

        // One row per channel that was considered. SMS is disabled in this
        // test's config, so it is recorded as failed with the reason rather
        // than left out - which is what lets staff see it was not sent.
        $this->assertSame(1, $this->logsFor(ClientNotificationService::CONFIRMED, 'email')->count());
        $this->assertSame(1, $this->logsFor(ClientNotificationService::CONFIRMED, 'sms')->count());
    }

    /**
     * A declared closure is enforced on the booking, not just hidden from the list.
     *
     * The slots on a holiday disappear from every booking screen, which reads like
     * the rule is being applied. It was not: the check lived only in
     * SlotAvailabilityService, so a client who had already chosen a slot before the
     * closure was declared - or who posts a time_slot_id directly - got a confirmed
     * appointment on a day the office is shut.
     *
     * Asserted through book() rather than through reschedule() because that is the
     * path that matters: reschedule was where this was found, but both go through
     * assertSlotIsBookable() and this pins the one clients use every day.
     */
    public function test_a_slot_on_a_declared_holiday_cannot_be_booked(): void
    {
        $date = now()->addDays(4)->toDateString();
        $holidaySlot = $this->slot($date, '09:00');

        Holiday::create([
            'name' => 'Foundation Day',
            'date' => $date,
        ]);

        $this->expectException(SlotUnavailableException::class);

        $this->bookViaService($holidaySlot);
    }

    public function test_a_slot_on_an_ordinary_day_is_unaffected(): void
    {
        $date = now()->addDays(5)->toDateString();

        // A closure on a different day must not block this one.
        Holiday::create([
            'name' => 'Some Other Day',
            'date' => now()->addDays(4)->toDateString(),
        ]);

        $appointment = $this->bookViaService($this->slot($date, '09:00'));

        $this->assertSame($date, $appointment->queue_date->toDateString());
    }

    /** Rows for one notification type and channel. */
    private function logsFor(string $type, string $channel)
    {
        return NotificationLog::where('type', $type)->where('channel', $channel)->get();
    }

    public function test_rescheduling_sends_a_move_notice(): void
    {
        Mail::fake();

        $first = $this->slot();
        $appointment = $this->bookViaService($first);

        $second = $this->slot(now()->addDays(3)->toDateString(), '11:00');

        app(BookingService::class)->reschedule($appointment, $second->id);

        Mail::assertQueued(AppointmentRescheduledMail::class);
        $this->assertSame(1, $this->logsFor(ClientNotificationService::RESCHEDULED, 'email')->count());
    }

    public function test_cancelling_sends_a_cancellation_notice(): void
    {
        Mail::fake();

        $appointment = $this->bookViaService($this->slot());

        app(BookingService::class)->cancel($appointment, 'Client request');

        Mail::assertQueued(AppointmentCancelledMail::class);
        $this->assertSame(1, $this->logsFor(ClientNotificationService::CANCELLED, 'email')->count());
    }

    public function test_cancelling_twice_does_not_send_two_notices(): void
    {
        Mail::fake();

        $appointment = $this->bookViaService($this->slot());
        $booking = app(BookingService::class);

        $booking->cancel($appointment, 'First');
        $booking->cancel($appointment->fresh(), 'Again');

        Mail::assertQueuedCount(2); // one confirmation, one cancellation
        $this->assertSame(1, $this->logsFor(ClientNotificationService::CANCELLED, 'email')->count());
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
