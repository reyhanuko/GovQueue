<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Mail\AppointmentNotCompletedMail;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\ClientNotificationService;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Telling the client their transaction could not be completed.
 *
 * The client was at the counter and the service could not be given - usually
 * because a document the service needs was not brought. The reason is what
 * they do next, so it has to reach them, and the only channel that says it
 * is the message.
 *
 * This is not the refusal case. A refusal is a judgement about a person's
 * entitlement, and the office has decided that one is spoken at the counter
 * rather than emailed. A transaction that could not be completed is a fact
 * about the service, and the client is told.
 */
class NotCompletedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private User $staff;

    private User $client;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create([
            'code' => 'WINDOW_1',
            'name' => 'Window 1 - Certificates',
        ]);

        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);

        $this->staff = User::factory()->staff()->create([
            'service_window_id' => $this->window->id,
        ]);

        $this->client = User::factory()->client()->create();
    }

    private function serving(array $attributes = []): Appointment
    {
        $this->slotCounter++;

        $slot = TimeSlot::factory()->create([
            'service_window_id' => $this->window->id,
            'service_id' => $this->service->id,
            'slot_date' => today()->toDateString(),
            'start_time' => sprintf('%02d:00:00', 9 + $this->slotCounter),
            'booked_count' => 1,
        ]);

        return Appointment::factory()->create(array_merge([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'status' => AppointmentStatus::Serving,
            'queue_number' => 'A-00'.$this->slotCounter,
        ], $attributes));
    }

    public function test_the_client_is_told_why_the_transaction_could_not_be_completed(): void
    {
        Mail::fake();

        $appointment = $this->serving();

        app(QueueService::class)->markNotCompleted(
            $appointment,
            $this->staff,
            'Client did not bring the PWD ID.',
        );

        Mail::assertQueued(
            AppointmentNotCompletedMail::class,
            function (AppointmentNotCompletedMail $mail) {
                return $mail->hasTo($this->client->email)
                    && str_contains($mail->render(), 'PWD ID');
            }
        );
    }

    public function test_the_attempt_is_recorded_for_staff_to_see(): void
    {
        Mail::fake();

        config()->set('govqueue.notifications.sms', false);

        $appointment = $this->serving();

        app(QueueService::class)->markNotCompleted(
            $appointment,
            $this->staff,
            'Client did not bring the PWD ID.',
        );

        $this->assertDatabaseHas('notification_logs', [
            'appointment_id' => $appointment->id,
            'type' => ClientNotificationService::NOT_COMPLETED,
        ]);
    }
}
