<?php

namespace Tests\Feature;

use App\Mail\AppointmentNoShowMail;
use App\Models\Appointment;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use App\Services\ClientNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Telling a client their appointment was closed out.
 *
 * The hourly close-out closes tickets nobody at the counter resolved. Doing
 * that silently leaves the client to find out from a screen that says their
 * appointment was closed and their place was given away - which is how a
 * resident discovers they are recorded as a no-show, and how the office
 * discovers the record is wrong.
 */
class NoShowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private ServiceWindow $window;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create(['email' => 'ana@example.test']);
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
    }

    private function missedAppointment(): Appointment
    {
        $this->slotCounter++;

        $start = now()->subDays(3)->setTime(9, $this->slotCounter * 10);

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        return Appointment::factory()->create([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'scheduled_at' => $slot->start_at,
            'queue_date' => $slot->slot_date,
            'status' => 'confirmed',
        ]);
    }

    public function test_the_client_is_emailed_when_their_appointment_is_closed_out(): void
    {
        Mail::fake();
        Notification::fake();

        $this->missedAppointment();

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        Mail::assertQueued(
            AppointmentNoShowMail::class,
            fn ($mail) => $mail->hasTo('ana@example.test')
                && str_contains($mail->envelope()->subject, 'closed out'),
        );
    }

    public function test_the_email_says_the_appointment_was_recorded_as_a_no_show(): void
    {
        Mail::fake();
        Notification::fake();

        $this->missedAppointment();

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        Mail::assertQueued(
            AppointmentNoShowMail::class,
            fn ($mail) => str_contains(
                $mail->render(),
                'Your appointment has been closed out and recorded as a no-show.',
            ),
        );
    }

    public function test_the_client_is_told_in_the_app_as_well(): void
    {
        Mail::fake();
        Notification::fake();

        $this->missedAppointment();

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        Notification::assertSentTo(
            $this->client,
            AppointmentNotification::class,
            fn ($notification) => $notification->type === ClientNotificationService::NO_SHOW,
        );
    }

    public function test_the_delivery_is_recorded(): void
    {
        Mail::fake();
        Notification::fake();

        $this->missedAppointment();

        $this->artisan('govqueue:close-stale-appointments')->assertSuccessful();

        // Every channel leaves a row, including the ones that decline to send,
        // so staff can tell "nothing was attempted" from "it failed".
        $this->assertSame(
            1,
            NotificationLog::where('user_id', $this->client->id)
                ->where('type', ClientNotificationService::NO_SHOW)
                ->where('channel', 'email')
                ->count(),
        );
    }

    public function test_a_ticket_that_was_not_closed_out_sends_nothing(): void
    {
        Mail::fake();
        Notification::fake();

        // A ticket for a slot that has not ended yet is left alone, and
        // nothing is said about it.
        $this->missedAppointment();

        $this->artisan('govqueue:close-stale-appointments --dry-run')->assertSuccessful();

        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }
}
