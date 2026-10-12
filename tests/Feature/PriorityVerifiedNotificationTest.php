<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Mail\AppointmentPriorityVerifiedMail;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\ClientNotificationService;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Telling the client their statutory priority was verified.
 *
 * This was the one queue action that changed who is served next without saying
 * so to anybody. The lobby board reorders, the audit trail records it, and the
 * client - the only party who cannot see the reorder - heard nothing.
 *
 * The refusal case is deliberately absent, and tested for its absence. Telling
 * someone by email that their disability or senior-citizen claim was rejected
 * is a decision for the office, not a default, and until it is made a refusal
 * is spoken at the counter. That boundary is asserted here so that adding the
 * other half later is a deliberate act rather than an oversight.
 */
class PriorityVerifiedNotificationTest extends TestCase
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

    private function waiting(array $attributes = []): Appointment
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
            'status' => AppointmentStatus::CheckedIn,
            'queue_number' => 'A-00'.$this->slotCounter,
        ], $attributes));
    }

    public function test_verifying_priority_queues_a_message_to_the_client(): void
    {
        Mail::fake();

        $appointment = $this->waiting(['claims_senior_citizen' => true]);

        app(QueueService::class)->verifyPriority($appointment, $this->staff);

        Mail::assertQueued(
            AppointmentPriorityVerifiedMail::class,
            fn (AppointmentPriorityVerifiedMail $mail) => $mail->hasTo($this->client->email)
        );
    }

    public function test_the_message_names_the_priority_that_was_actually_granted(): void
    {
        Mail::fake();

        // Two claimed, both verified. The message must describe the decision,
        // not the raw claims, and certainly not a category that was refused.
        $appointment = $this->waiting([
            'claims_senior_citizen' => true,
            'claims_pwd' => true,
            'priority_reference' => 'PWD-1',
        ]);

        app(QueueService::class)->verifyPriority($appointment, $this->staff);

        Mail::assertQueued(function (AppointmentPriorityVerifiedMail $mail) {
            $rendered = $mail->render();

            return str_contains($rendered, 'Senior Citizen')
                && str_contains($rendered, 'Person with Disability')
                && ! str_contains($rendered, 'Pregnant');
        });
    }

    public function test_a_refused_claim_sends_nothing(): void
    {
        Mail::fake();

        $appointment = $this->waiting(['claims_senior_citizen' => true]);

        app(QueueService::class)->refusePriority($appointment, $this->staff, 'No ID presented');

        // Deliberate, and asserted so it stays deliberate. See the class
        // docblock: a refusal is a conversation, not an email.
        Mail::assertNothingQueued();
    }

    public function test_the_delivery_attempt_is_recorded_for_staff_to_see(): void
    {
        Mail::fake();

        config()->set('govqueue.notifications.sms', false);

        $appointment = $this->waiting(['claims_pwd' => true, 'priority_reference' => 'PWD-9']);

        app(QueueService::class)->verifyPriority($appointment, $this->staff);

        $this->assertDatabaseHas('notification_logs', [
            'appointment_id' => $appointment->id,
            'type' => ClientNotificationService::PRIORITY_VERIFIED,
            'channel' => 'email',
        ]);
    }

    /**
     * The property that justifies deferring the send.
     *
     * Sent inline, the email could outlive the transaction that granted the
     * priority: a deadlock retry or a later failure rolls the grant back, and
     * the client is left holding confirmation of something the system no longer
     * believes. An email cannot be rolled back, which is why this fires from
     * DB::afterCommit rather than at the point of the decision.
     */
    public function test_a_rolled_back_verification_sends_nothing(): void
    {
        Mail::fake();

        $appointment = $this->waiting(['claims_senior_citizen' => true]);

        DB::beginTransaction();

        try {
            app(QueueService::class)->verifyPriority($appointment, $this->staff);

            // Still nothing: the outermost transaction has not committed.
            Mail::assertNothingQueued();
        } finally {
            DB::rollBack();
        }

        Mail::assertNothingQueued();
    }
}
