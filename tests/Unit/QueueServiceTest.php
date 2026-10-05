<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use App\Exceptions\InvalidQueueTransitionException;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BookingService;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The queue lifecycle at the counter.
 */
class QueueServiceTest extends TestCase
{
    use RefreshDatabase;

    private QueueService $queue;

    private ServiceWindow $window;

    private Service $service;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new QueueService(app(BookingService::class));
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1', 'avg_service_minutes' => 10]);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
        $this->staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);
    }

    private int $slotCounter = 0;

    private function slot(): TimeSlot
    {
        // Each call takes its own 30 minute step: time_slots has a unique index
        // on (service, date, start_time), so reusing a time would collide.
        $this->slotCounter++;
        $start = now()->startOfDay()->setTime(9, 0)->addMinutes(($this->slotCounter - 1) * 30);
        $end = $start->copy()->addMinutes(30);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => today()->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $end->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    /** A client who has booked and is waiting to be called. */
    private function waiting(AppointmentStatus|string $status = AppointmentStatus::CheckedIn, array $attrs = []): Appointment
    {
        $status = $status instanceof AppointmentStatus ? $status : AppointmentStatus::from($status);
        $slot = $attrs['slot'] ?? $this->slot();

        $appointment = Appointment::create(array_merge([
            'reference_number' => 'GQ-T-'.uniqid(),
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'status' => $status,
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_number' => 'A-'.str_pad((string) (Appointment::count() + 1), 3, '0', STR_PAD_LEFT),
            'queue_date' => today()->toDateString(),
            'confirmed_at' => now(),
            'checked_in_at' => $status === AppointmentStatus::CheckedIn ? now() : null,
        ], $attrs['overrides'] ?? []));

        $slot->increment('booked_count');

        return $appointment;
    }

    /* ------------------------------------------------------------------ */
    /* Check-in */
    /* ------------------------------------------------------------------ */

    public function test_a_confirmed_client_can_check_in(): void
    {
        $appointment = $this->waiting(AppointmentStatus::Confirmed);

        $checkedIn = $this->queue->checkIn($appointment, $this->staff);

        $this->assertSame(AppointmentStatus::CheckedIn, $checkedIn->status);
        $this->assertNotNull($checkedIn->checked_in_at);
    }

    public function test_check_in_is_recorded_in_the_history(): void
    {
        $appointment = $this->waiting(AppointmentStatus::Confirmed);

        $this->queue->checkIn($appointment, $this->staff);

        $this->assertDatabaseHas('queue_status_histories', [
            'appointment_id' => $appointment->id,
            'from_status' => 'confirmed',
            'to_status' => 'checked_in',
        ]);
    }

    public function test_an_already_checked_in_client_cannot_check_in_twice(): void
    {
        $this->expectException(InvalidQueueTransitionException::class);

        $this->queue->checkIn($this->waiting(), $this->staff);
    }

    /* ------------------------------------------------------------------ */
    /* Calling */
    /* ------------------------------------------------------------------ */

    public function test_call_next_serves_the_first_client_in_order(): void
    {
        $first = $this->waiting();
        $second = $this->waiting();

        $called = $this->queue->callNext($this->window, $this->staff);

        $this->assertNotNull($called);
        $this->assertSame($first->id, $called->id);
        $this->assertSame(AppointmentStatus::Serving, $called->status);
        $this->assertNotNull($called->serving_started_at);
    }

    public function test_call_next_applies_statutory_priority_automatically(): void
    {
        // A regular client arrives first, but a verified PWD must go before them.
        $regular = $this->waiting();
        $pwd = $this->waiting(AppointmentStatus::CheckedIn, [
            'overrides' => [
                'claims_pwd' => true,
                'priority_verified_at' => now(),
                'priority_reference' => 'PWD-1',
            ],
        ]);

        $called = $this->queue->callNext($this->window, $this->staff);

        $this->assertSame($pwd->id, $called->id,
            'RA 10754 priority must be applied by the queue, not the operator.');

        $this->waiting()->each(function () {});
        $this->assertSame(AppointmentStatus::CheckedIn, $regular->fresh()->status);
    }

    public function test_call_next_returns_null_when_nobody_is_waiting(): void
    {
        $this->assertNull($this->queue->callNext($this->window, $this->staff));
    }

    public function test_call_next_refuses_while_someone_is_being_served(): void
    {
        $this->waiting();
        $this->waiting();

        $this->assertNotNull($this->queue->callNext($this->window, $this->staff));
        $this->assertNull($this->queue->callNext($this->window, $this->staff),
            'Only one client may be at the window at a time.');
    }

    public function test_the_queue_drains_in_order(): void
    {
        $ids = collect(range(1, 4))->map(fn () => $this->waiting()->id)->all();

        $served = [];

        while ($called = $this->queue->callNext($this->window, $this->staff)) {
            $served[] = $called->id;
            $this->queue->complete($called, $this->staff);
        }

        $this->assertSame($ids, $served);
    }

    /* ------------------------------------------------------------------ */
    /* Completing */
    /* ------------------------------------------------------------------ */

    public function test_completing_records_a_transaction(): void
    {
        $this->waiting();
        $called = $this->queue->callNext($this->window, $this->staff);

        $completed = $this->queue->complete($called, $this->staff, [
            'details' => ['note' => 'Issued'],
            'fee_paid' => 100,
        ]);

        $this->assertSame(AppointmentStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);

        $this->assertDatabaseHas('transactions', [
            'appointment_id' => $completed->id,
            'handled_by' => $this->staff->id,
            'fee_paid' => 100,
        ]);
    }

    public function test_completing_releases_the_slot(): void
    {
        $appointment = $this->waiting();
        $slotId = $appointment->time_slot_id;
        $this->assertSame(1, (int) TimeSlot::find($slotId)->booked_count);

        $called = $this->queue->callNext($this->window, $this->staff);
        $this->queue->complete($called, $this->staff);

        $this->assertSame(0, (int) TimeSlot::find($slotId)->booked_count);
    }

    public function test_completing_does_not_disturb_other_slots(): void
    {
        $this->waiting();
        $other = $this->waiting();
        $otherSlotId = $other->time_slot_id;

        $called = $this->queue->callNext($this->window, $this->staff);
        $this->queue->complete($called, $this->staff);

        $this->assertSame(1, (int) TimeSlot::find($otherSlotId)->booked_count);
    }

    public function test_a_completed_client_cannot_be_completed_again(): void
    {
        $this->waiting();
        $called = $this->queue->callNext($this->window, $this->staff);
        $this->queue->complete($called, $this->staff);

        $this->expectException(InvalidQueueTransitionException::class);
        $this->queue->complete($called->fresh(), $this->staff);
    }

    public function test_transaction_references_are_unique(): void
    {
        $references = [];

        foreach (range(1, 3) as $i) {
            $this->waiting();
            $called = $this->queue->callNext($this->window, $this->staff);
            $this->queue->complete($called, $this->staff);
            $references[] = Transaction::latest('id')->value('reference_number');
        }

        $this->assertCount(3, array_unique($references));
    }

    /* ------------------------------------------------------------------ */
    /* No-shows and skips */
    /* ------------------------------------------------------------------ */

    public function test_a_client_can_be_marked_a_no_show(): void
    {
        $this->waiting();
        $called = $this->queue->callNext($this->window, $this->staff);

        $noShow = $this->queue->markNoShow($called, $this->staff);

        $this->assertSame(AppointmentStatus::NoShow, $noShow->status);
        $this->assertSame(0, (int) TimeSlot::find($called->time_slot_id)->booked_count);
    }

    public function test_a_no_show_frees_the_window_for_the_next_client(): void
    {
        $this->waiting();
        $this->waiting();

        $called = $this->queue->callNext($this->window, $this->staff);
        $this->queue->markNoShow($called, $this->staff);

        $this->assertNotNull($this->queue->callNext($this->window, $this->staff));
    }

    public function test_skipping_returns_the_client_to_the_line(): void
    {
        // Explicit arrival times: two clients checked in within the same second
        // would otherwise be ordered by reference number, not by who arrived first.
        $first = $this->waiting(AppointmentStatus::CheckedIn, [
            'overrides' => ['checked_in_at' => now()->subMinutes(10)],
        ]);
        $second = $this->waiting(AppointmentStatus::CheckedIn, [
            'overrides' => ['checked_in_at' => now()->subMinutes(5)],
        ]);

        $called = $this->queue->callNext($this->window, $this->staff);
        $this->assertSame($first->id, $called->id);

        $skipped = $this->queue->skip($called, $this->staff);
        $this->assertSame(AppointmentStatus::Skipped, $skipped->status);
        $this->assertSame(1, $skipped->skip_count);

        // The client who was passed is served next.
        $this->assertSame($second->id, $this->queue->callNext($this->window, $this->staff)->id);
    }

    public function test_skipping_releases_serving_start_time(): void
    {
        $this->waiting();
        $called = $this->queue->callNext($this->window, $this->staff);
        $this->assertNotNull($called->serving_started_at);

        $skipped = $this->queue->skip($called, $this->staff);

        $this->assertNull($skipped->serving_started_at,
            'A skipped client is not being served, so the timer must reset.');
    }

    public function test_repeated_skipping_is_flagged(): void
    {
        $this->waiting();
        $appointment = $this->waiting();

        $appointment = $this->queue->skip($appointment, $this->staff);
        $this->assertFalse($this->queue->hasBeenSkippedTooOften($appointment));

        $appointment = $this->queue->skip($appointment, $this->staff);

        $this->assertTrue($this->queue->hasBeenSkippedTooOften($appointment),
            'After the configured limit staff should be prompted to close the ticket.');
    }

    public function test_a_skipped_client_still_holds_their_slot(): void
    {
        $appointment = $this->waiting();

        $this->queue->skip($appointment, $this->staff);

        $this->assertSame(1, (int) TimeSlot::find($appointment->time_slot_id)->booked_count,
            'Skipping is not a cancellation: the place is still held.');
    }

    /* ------------------------------------------------------------------ */
    /* Priority verification */
    /* ------------------------------------------------------------------ */

    public function test_staff_can_verify_a_priority_claim(): void
    {
        $appointment = $this->waiting(AppointmentStatus::CheckedIn, [
            'overrides' => [
                'claims_pwd' => true,
                'priority_reference' => 'PWD-999',
            ],
        ]);

        $verified = $this->queue->verifyPriority($appointment, $this->staff);

        $this->assertNotNull($verified->priority_verified_at);
        $this->assertSame($this->staff->id, $verified->priority_verified_by);
        $this->assertTrue($verified->hasVerifiedPriority());
    }

    public function test_verifying_records_the_decision_for_audit(): void
    {
        $appointment = $this->waiting(AppointmentStatus::CheckedIn, [
            'overrides' => ['claims_senior_citizen' => true],
        ]);

        $this->queue->verifyPriority($appointment, $this->staff);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment.priority_verified',
            'subject_id' => $appointment->id,
        ]);
    }

    public function test_staff_can_refuse_a_priority_claim(): void
    {
        $appointment = $this->waiting(AppointmentStatus::CheckedIn, [
            'overrides' => ['claims_pwd' => true, 'priority_reference' => 'PWD-999'],
        ]);

        $refused = $this->queue->refusePriority($appointment, $this->staff, 'No ID presented');

        $this->assertFalse($refused->claims_pwd);
        $this->assertNull($refused->priority_verified_at);
        $this->assertFalse($refused->hasVerifiedPriority());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment.priority_refused',
            'subject_id' => $appointment->id,
        ]);
    }

    public function test_verification_reorders_the_queue(): void
    {
        $regular = $this->waiting(AppointmentStatus::CheckedIn, [
            'overrides' => ['checked_in_at' => now()->subMinutes(10)],
        ]);
        $claiming = $this->waiting(AppointmentStatus::CheckedIn, [
            'overrides' => [
                'claims_senior_citizen' => true,
                'priority_reference' => 'SC-1',
                'checked_in_at' => now()->subMinutes(5),
            ],
        ]);

        // Before verification the first arrival leads.
        $this->assertSame($regular->id, $this->queue->waitingFor($this->window)->first()->id);

        $this->queue->verifyPriority($claiming, $this->staff);

        $this->assertSame($claiming->id, $this->queue->waitingFor($this->window)->first()->id,
            'A verified priority claim must move the client up the line.');
    }

    /* ------------------------------------------------------------------ */
    /* Wait time estimation */
    /* ------------------------------------------------------------------ */

    public function test_wait_estimates_scale_with_position(): void
    {
        $this->waiting();
        $this->waiting();
        $this->waiting();

        $queue = $this->queue->waitingFor($this->window);
        $estimates = $this->queue->estimateWaits($this->window, $queue);

        $ids = $queue->pluck('id')->all();

        $this->assertSame(0, $estimates[$ids[0]], 'The next client waits zero minutes.');
        $this->assertSame(10, $estimates[$ids[1]], 'One ahead at 10 minutes each.');
        $this->assertSame(20, $estimates[$ids[2]]);
    }

    public function test_wait_estimates_use_the_window_default_before_enough_history(): void
    {
        $this->waiting();
        $queue = $this->queue->waitingFor($this->window);

        // Fewer than five completions: fall back to the configured average.
        $this->assertSame(10.0, $this->queue->averageServiceMinutes($this->window));
    }

    public function test_wait_estimates_adopt_measured_service_times(): void
    {
        // Seed enough history with a deliberately different duration.
        foreach (range(1, 6) as $i) {
            $appointment = Appointment::create([
                'reference_number' => 'GQ-H-'.$i,
                'user_id' => User::factory()->client()->create()->id,
                'service_id' => $this->service->id,
                'time_slot_id' => $this->slot()->id,
                'service_window_id' => $this->window->id,
                'status' => AppointmentStatus::Completed,
                'source' => 'online',
                'scheduled_at' => now()->subDay(),
                'queue_date' => today()->subDay()->toDateString(),
                'serving_started_at' => now()->subDay(),
                'completed_at' => now()->subDay()->addMinutes(25),
            ]);
        }

        $this->assertSame(25.0, $this->queue->averageServiceMinutes($this->window),
            'With enough history the measured average should be used.');
    }

    public function test_estimates_are_empty_for_an_empty_queue(): void
    {
        $this->assertSame([], $this->queue->estimateWaits($this->window, collect()));
    }
}
