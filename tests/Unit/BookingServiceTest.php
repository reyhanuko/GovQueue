<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use App\Exceptions\AppointmentConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The booking rules, with particular attention to slot capacity control.
 */
class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $booking;

    private User $client;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->booking = new BookingService;
        $this->client = User::factory()->client()->create();
        $this->service = Service::factory()->create();
    }

    private function slot(array $overrides = []): TimeSlot
    {
        $start = $overrides['start_at'] ?? now()->addDay()->setTime(9, 0)->toDateTimeString();

        return TimeSlot::create(array_merge([
            'service_id' => $this->service->id,
            'service_window_id' => $this->service->service_window_id,
            'slot_date' => substr($start, 0, 10),
            'start_time' => substr($start, 11, 8),
            'end_time' => date('H:i:s', strtotime($start) + 1800),
            'start_at' => $start,
            'end_at' => date('Y-m-d H:i:s', strtotime($start) + 1800),
            'capacity' => 2,
            'booked_count' => 0,
            'is_open' => true,
        ], $overrides));
    }

    /* ------------------------------------------------------------------ */
    /* Happy path */
    /* ------------------------------------------------------------------ */

    public function test_a_client_can_book_an_open_slot(): void
    {
        $slot = $this->slot();

        $appointment = $this->booking->book($this->client, $this->service, $slot->id);

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame($slot->id, $appointment->time_slot_id);
        $this->assertSame('A-001', $appointment->queue_number);
        $this->assertSame($slot->start_at->toDateTimeString(), $appointment->scheduled_at->toDateTimeString());
        $this->assertNotNull($appointment->confirmed_at);
    }

    public function test_booking_increments_the_slot_capacity_counter(): void
    {
        $slot = $this->slot();

        $this->booking->book($this->client, $this->service, $slot->id);

        $this->assertSame(1, $slot->fresh()->booked_count);
        $this->assertSame(1, $slot->fresh()->remainingCapacity());
    }

    public function test_each_booking_gets_a_unique_reference_and_queue_number(): void
    {
        $slotA = $this->slot(['capacity' => 5]);
        $slotB = $this->slot(['start_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(), 'capacity' => 5]);

        $other = User::factory()->client()->create();

        $first = $this->booking->book($this->client, $this->service, $slotA->id);
        $second = $this->booking->book($other, $this->service, $slotB->id);

        $this->assertNotSame($first->reference_number, $second->reference_number);
        $this->assertSame('A-001', $first->queue_number);
        $this->assertSame('A-002', $second->queue_number);
    }

    public function test_queue_numbers_are_per_window_and_per_day(): void
    {
        $tomorrow = $this->slot();
        $later = $this->slot(['start_at' => now()->addDays(3)->setTime(9, 0)->toDateTimeString()]);
        $otherWindowService = Service::factory()->create();
        $otherWindow = $this->slot([
            'service_id' => $otherWindowService->id,
            'service_window_id' => ServiceWindow::factory()->create()->id,
        ]);

        $clientA = User::factory()->client()->create();
        $clientB = User::factory()->client()->create();
        $clientC = User::factory()->client()->create();

        $first = $this->booking->book($clientA, $this->service, $tomorrow->id);
        $sameWindowNextDay = $this->booking->book($clientB, $this->service, $later->id);
        $differentWindow = $this->booking->book($clientC, $otherWindowService, $otherWindow->id);

        // Same window, different day: the counter restarts at A-001.
        $this->assertSame('A-001', $first->queue_number);
        $this->assertSame('A-001', $sameWindowNextDay->queue_number);
        // Different window, same day: an independent sequence.
        $this->assertSame('A-001', $differentWindow->queue_number);
    }

    public function test_booking_records_an_audit_trail_entry(): void
    {
        $appointment = $this->booking->book($this->client, $this->service, $this->slot()->id);

        $this->assertDatabaseHas('queue_status_histories', [
            'appointment_id' => $appointment->id,
            'to_status' => 'confirmed',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment.booked',
            'subject_id' => $appointment->id,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Slot capacity control - the core requirement */
    /* ------------------------------------------------------------------ */

    public function test_a_slot_stops_accepting_bookings_at_capacity(): void
    {
        $slot = $this->slot(['capacity' => 2]);

        $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);
        $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);

        $this->assertSame(2, $slot->fresh()->booked_count);

        $this->expectException(SlotUnavailableException::class);
        $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);
    }

    public function test_a_refused_booking_does_not_increment_the_counter(): void
    {
        $slot = $this->slot(['capacity' => 1]);
        $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);

        try {
            $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);
            $this->fail('Expected the booking to be refused.');
        } catch (SlotUnavailableException) {
            // Expected.
        }

        $this->assertSame(1, $slot->fresh()->booked_count,
            'A refused booking must not consume capacity.');
    }

    public function test_a_full_slot_reports_a_full_reason(): void
    {
        $slot = $this->slot(['capacity' => 1]);
        $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);

        try {
            $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);
            $this->fail('Expected the booking to be refused.');
        } catch (SlotUnavailableException $e) {
            $this->assertSame('full', $e->reason);
        }
    }

    public function test_a_closed_slot_cannot_be_booked(): void
    {
        $slot = $this->slot(['is_open' => false]);

        try {
            $this->booking->book($this->client, $this->service, $slot->id);
            $this->fail('Expected the booking to be refused.');
        } catch (SlotUnavailableException $e) {
            $this->assertSame('closed', $e->reason);
        }
    }

    public function test_a_slot_in_the_past_cannot_be_booked(): void
    {
        $slot = $this->slot([
            'start_at' => now()->subDay()->toDateTimeString(),
            'slot_date' => now()->subDay()->toDateString(),
        ]);

        try {
            $this->booking->book($this->client, $this->service, $slot->id);
            $this->fail('Expected the booking to be refused.');
        } catch (SlotUnavailableException $e) {
            $this->assertSame('past', $e->reason);
        }
    }

    public function test_a_slot_beyond_the_booking_horizon_cannot_be_booked(): void
    {
        $far = now()->addDays((int) config('govqueue.booking_horizon_days') + 10);
        $slot = $this->slot([
            'start_at' => $far->toDateTimeString(),
            'slot_date' => $far->toDateString(),
        ]);

        try {
            $this->booking->book($this->client, $this->service, $slot->id);
            $this->fail('Expected the booking to be refused.');
        } catch (SlotUnavailableException $e) {
            $this->assertSame('outside_horizon', $e->reason);
        }
    }

    public function test_a_slot_belonging_to_another_service_is_rejected(): void
    {
        $slot = $this->slot();

        $this->expectException(SlotUnavailableException::class);
        $this->booking->book($this->client, Service::factory()->create(), $slot->id);
    }

    public function test_a_missing_slot_is_rejected(): void
    {
        $this->expectException(SlotUnavailableException::class);
        $this->booking->book($this->client, $this->service, 999999);
    }

    /* ------------------------------------------------------------------ */
    /* Conflict detection */
    /* ------------------------------------------------------------------ */

    public function test_a_client_cannot_hold_two_live_bookings_for_one_service(): void
    {
        $this->booking->book($this->client, $this->service, $this->slot()->id);

        try {
            $this->booking->book($this->client, $this->service, $this->slot([
                'start_at' => now()->addDay()->setTime(14, 0)->toDateTimeString(),
            ])->id);
            $this->fail('Expected a duplicate-service conflict.');
        } catch (AppointmentConflictException $e) {
            $this->assertSame('duplicate_service', $e->reason);
        }
    }

    public function test_overlapping_slots_are_rejected(): void
    {
        // 09:00-09:30 booked, then 09:30-10:00 attempted for another service.
        $booked = $this->slot(['start_at' => now()->addDay()->setTime(9, 0)->toDateTimeString()]);
        $this->booking->book($this->client, $this->service, $booked->id);

        $otherService = Service::factory()->create();
        $overlapping = $this->slot([
            'service_id' => $otherService->id,
            'service_window_id' => ServiceWindow::factory()->create()->id,
            'start_at' => now()->addDay()->setTime(9, 15)->toDateTimeString(),
        ]);

        try {
            $this->booking->book($this->client, $otherService, $overlapping->id);
            $this->fail('Expected an overlap conflict.');
        } catch (AppointmentConflictException $e) {
            $this->assertSame('overlapping_slot', $e->reason);
        }
    }

    public function test_non_overlapping_slots_are_allowed_for_different_services(): void
    {
        // 09:00-09:30, then 10:00-10:30 on the same day: no overlap.
        $this->booking->book($this->client, $this->service, $this->slot([
            'start_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        ])->id);

        $otherService = Service::factory()->create();
        $later = $this->slot([
            'service_id' => $otherService->id,
            'service_window_id' => ServiceWindow::factory()->create()->id,
            'start_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
        ]);

        $appointment = $this->booking->book($this->client, $otherService, $later->id);

        $this->assertSame('confirmed', $appointment->status->value);
    }

    public function test_a_cancelled_appointment_does_not_block_a_new_booking(): void
    {
        $slot = $this->slot();
        $appointment = $this->booking->book($this->client, $this->service, $slot->id);
        $this->booking->cancel($appointment, 'Changed my mind');

        $second = $this->booking->book($this->client, $this->service, $this->slot([
            'start_at' => now()->addDay()->setTime(15, 0)->toDateTimeString(),
        ])->id);

        $this->assertSame('confirmed', $second->status->value);
    }

    /* ------------------------------------------------------------------ */
    /* Priority claims are claims only */
    /* ------------------------------------------------------------------ */

    public function test_a_claimed_priority_is_stored_but_not_verified(): void
    {
        $appointment = $this->booking->book($this->client, $this->service, $this->slot()->id, [
            'claims_pwd' => true,
            'priority_reference' => 'PWD-1234567',
        ]);

        $this->assertTrue($appointment->claims_pwd);
        $this->assertSame('PWD-1234567', $appointment->priority_reference);
        $this->assertNull($appointment->priority_verified_at,
            'A claim must not be verified automatically.');
        $this->assertFalse($appointment->hasVerifiedPriority());
    }

    public function test_senior_priority_is_accepted_as_a_claim(): void
    {
        $senior = User::factory()->seniorCitizen()->create();

        $appointment = $this->booking->book($senior, $this->service, $this->slot()->id, [
            'claims_senior_citizen' => true,
        ]);

        $this->assertTrue($appointment->claims_senior_citizen);
        $this->assertFalse($appointment->hasVerifiedPriority());
    }

    /* ------------------------------------------------------------------ */
    /* Reschedule */
    /* ------------------------------------------------------------------ */

    public function test_rescheduling_moves_the_booking_and_swaps_capacity(): void
    {
        $old = $this->slot(['capacity' => 3]);
        $new = $this->slot([
            'start_at' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
            'capacity' => 3,
        ]);

        $appointment = $this->booking->book($this->client, $this->service, $old->id);

        $moved = $this->booking->reschedule($appointment, $new->id);

        $this->assertSame($new->id, $moved->time_slot_id);
        $this->assertSame($new->start_at->toDateTimeString(), $moved->scheduled_at->toDateTimeString());
        $this->assertSame(0, $old->fresh()->booked_count, 'The old slot must release its place.');
        $this->assertSame(1, $new->fresh()->booked_count, 'The new slot must claim a place.');
    }

    public function test_rescheduling_reissues_the_queue_number_for_the_new_day(): void
    {
        $appointment = $this->booking->book($this->client, $this->service, $this->slot()->id);
        $this->assertSame('A-001', $appointment->queue_number);

        // Somebody else already holds A-001 on the destination day, so moving
        // here must place the client behind them rather than reuse the number.
        $destinationDate = now()->addDays(4)->setTime(11, 0);
        $someoneElse = User::factory()->client()->create();
        $this->booking->book($someoneElse, $this->service, $this->slot([
            'start_at' => $destinationDate->toDateTimeString(),
        ])->id);

        $moved = $this->booking->reschedule($appointment, $this->slot([
            'start_at' => $destinationDate->copy()->addMinutes(30)->toDateTimeString(),
        ])->id);

        $this->assertSame('A-002', $moved->queue_number,
            'The new day has its own sequence and must not collide with A-001.');
    }

    public function test_rescheduling_to_a_full_slot_is_refused(): void
    {
        $appointment = $this->booking->book($this->client, $this->service, $this->slot()->id);

        $full = $this->slot([
            'start_at' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
            'capacity' => 1,
            'booked_count' => 1,
        ]);

        $this->expectException(SlotUnavailableException::class);
        $this->booking->reschedule($appointment, $full->id);
    }

    public function test_a_refused_reschedule_keeps_the_original_booking_intact(): void
    {
        $old = $this->slot();
        $appointment = $this->booking->book($this->client, $this->service, $old->id);

        $full = $this->slot([
            'start_at' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
            'capacity' => 1,
            'booked_count' => 1,
        ]);

        try {
            $this->booking->reschedule($appointment, $full->id);
            $this->fail('Expected the reschedule to be refused.');
        } catch (SlotUnavailableException) {
            // Expected.
        }

        $appointment->refresh();
        $this->assertSame($old->id, $appointment->time_slot_id);
        $this->assertSame(1, $old->fresh()->booked_count,
            'A refused reschedule must not release the original slot.');
    }

    public function test_rescheduling_does_not_conflict_with_itself(): void
    {
        $appointment = $this->booking->book($this->client, $this->service, $this->slot()->id);

        $later = $this->slot([
            'start_at' => now()->addDays(5)->setTime(13, 0)->toDateTimeString(),
        ]);

        $moved = $this->booking->reschedule($appointment, $later->id);

        $this->assertSame($later->id, $moved->time_slot_id);
    }

    public function test_a_started_appointment_cannot_be_moved(): void
    {
        $appointment = $this->booking->book($this->client, $this->service, $this->slot()->id);
        $appointment->update(['status' => AppointmentStatus::CheckedIn]);

        $this->expectException(AppointmentConflictException::class);
        $this->booking->reschedule($appointment, $this->slot([
            'start_at' => now()->addDays(2)->setTime(9, 0)->toDateTimeString(),
        ])->id);
    }

    /* ------------------------------------------------------------------ */
    /* Cancellation */
    /* ------------------------------------------------------------------ */

    public function test_cancelling_releases_the_slot(): void
    {
        $slot = $this->slot();
        $appointment = $this->booking->book($this->client, $this->service, $slot->id);

        $cancelled = $this->booking->cancel($appointment, 'Schedule conflict', $this->client);

        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('Schedule conflict', $cancelled->cancel_reason);
        $this->assertSame(0, $slot->fresh()->booked_count);
    }

    public function test_a_cancelled_slot_can_be_booked_again(): void
    {
        $slot = $this->slot(['capacity' => 1]);
        $appointment = $this->booking->book($this->client, $this->service, $slot->id);
        $this->booking->cancel($appointment);

        $second = $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);

        $this->assertSame('confirmed', $second->status->value);
        $this->assertSame(1, $slot->fresh()->booked_count);
    }

    public function test_cancelling_twice_does_not_double_release_capacity(): void
    {
        $slot = $this->slot();
        $appointment = $this->booking->book($this->client, $this->service, $slot->id);

        $this->booking->cancel($appointment);
        $this->booking->cancel($appointment->fresh());

        $this->assertSame(0, $slot->fresh()->booked_count,
            'The counter must not go negative on a repeated cancellation.');
    }

    public function test_cancellation_is_audited(): void
    {
        $appointment = $this->booking->book($this->client, $this->service, $this->slot()->id);
        $this->booking->cancel($appointment, 'Cannot make it');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment.cancelled',
            'subject_id' => $appointment->id,
        ]);

        $this->assertDatabaseHas('queue_status_histories', [
            'appointment_id' => $appointment->id,
            'from_status' => 'confirmed',
            'to_status' => 'cancelled',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Data integrity */
    /* ------------------------------------------------------------------ */

    public function test_booked_count_matches_the_number_of_live_appointments(): void
    {
        $slot = $this->slot(['capacity' => 5]);

        $first = $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);
        $second = $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);
        $third = $this->booking->book(User::factory()->client()->create(), $this->service, $slot->id);

        $this->booking->cancel($third);

        $liveCount = Appointment::where('time_slot_id', $slot->id)
            ->whereIn('status', ['pending', 'confirmed', 'checked_in', 'serving'])
            ->count();

        $this->assertSame(2, $liveCount);
        $this->assertSame($liveCount, $slot->fresh()->booked_count,
            'The denormalised counter must stay in step with the bookings.');
    }

    /* ------------------------------------------------------------------ */
    /* Cross-slot isolation */
    /* ------------------------------------------------------------------ */

    public function test_releasing_one_slot_does_not_disturb_another(): void
    {
        // Regression guard: an unconstrained decrement() once released capacity
        // on every slot in the table, not just the one being cancelled.
        $first = $this->slot(['capacity' => 3]);
        $second = $this->slot([
            'start_at' => now()->addDay()->setTime(14, 0)->toDateTimeString(),
            'capacity' => 3,
        ]);

        $firstAppointment = $this->booking->book(User::factory()->client()->create(), $this->service, $first->id);
        $this->booking->book(User::factory()->client()->create(), $this->service, $second->id);

        $this->assertSame(1, $second->fresh()->booked_count);

        $this->booking->cancel($firstAppointment);

        $this->assertSame(0, $first->fresh()->booked_count);
        $this->assertSame(1, $second->fresh()->booked_count,
            'Cancelling one slot must leave every other slot untouched.');
    }

    public function test_rescheduling_does_not_disturb_a_third_slot(): void
    {
        $from = $this->slot(['capacity' => 3]);
        $to = $this->slot([
            'start_at' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
            'capacity' => 3,
        ]);
        $untouched = $this->slot([
            'start_at' => now()->addDays(3)->setTime(9, 0)->toDateTimeString(),
            'capacity' => 3,
        ]);

        $appointment = $this->booking->book(User::factory()->client()->create(), $this->service, $from->id);
        $this->booking->book(User::factory()->client()->create(), $this->service, $untouched->id);

        $this->booking->reschedule($appointment, $to->id);

        $this->assertSame(0, $from->fresh()->booked_count);
        $this->assertSame(1, $to->fresh()->booked_count);
        $this->assertSame(1, $untouched->fresh()->booked_count);
    }

    public function test_reference_numbers_are_unique(): void
    {
        $references = collect(range(1, 20))
            ->map(fn () => $this->booking->generateReferenceNumber())
            ->all();

        $this->assertCount(20, array_unique($references));
    }
}
