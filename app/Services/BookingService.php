<?php

namespace App\Services;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\PriorityType;
use App\Exceptions\AppointmentConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\QueueCounter;
use App\Models\QueueStatusHistory;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Booking and slot-capacity control.
 *
 * This is the core of the system's first approved feature, so the concurrency
 * behaviour matters more than anything else here:
 *
 *  - The slot row is re-read inside the transaction with SELECT ... FOR UPDATE.
 *    Two clients racing for the last place serialise on that lock; the second
 *    one wakes up seeing booked_count == capacity and is refused. Reading the
 *    slot *before* opening the transaction would leave that window wide open.
 *
 *  - Capacity is a single denormalised counter on time_slots, incremented in the
 *    same transaction as the appointment insert. There is no separate count
 *    query to fall out of sync with the bookings.
 *
 *  - The queue number comes from a locked counter row, so concurrent bookings in
 *    the same window on the same day can never be issued the same number.
 */
class BookingService
{
    /* ------------------------------------------------------------------ */
    /* Booking */
    /* ------------------------------------------------------------------ */

    /**
     * Book a slot for a client.
     *
     * @param  array{claims_senior_citizen?:bool, claims_pwd?:bool, claims_pregnant?:bool, priority_reference?:?string, client_note?:?string}  $claims
     *
     * @throws SlotUnavailableException|AppointmentConflictException
     */
    public function book(User $client, Service $service, int $timeSlotId, array $claims = []): Appointment
    {
        return DB::transaction(function () use ($client, $service, $timeSlotId, $claims) {
            // Re-read the slot under a row lock. Everything below trusts this
            // copy, which is why it must be fetched inside the transaction.
            //
            // GOVQUEUE_DISABLE_ROW_LOCK exists purely so the concurrency test can
            // prove the lock is what enforces capacity: with it set, the race in
            // BookingConcurrencyTest overshoots the capacity and the test fails.
            // Never set it in production.
            $slot = TimeSlot::whereKey($timeSlotId)->when(
                ! filter_var(env('GOVQUEUE_DISABLE_ROW_LOCK', false), FILTER_VALIDATE_BOOLEAN),
                fn ($q) => $q->lockForUpdate(),
            )->first();

            if (! $slot) {
                throw SlotUnavailableException::notFound();
            }

            if ((int) $slot->service_id !== (int) $service->id) {
                throw SlotUnavailableException::mismatchedService();
            }

            $this->assertSlotIsBookable($slot);

            // Conflict checks run inside the lock too, so a client cannot slip two
            // overlapping bookings past each other.
            $this->assertNoConflict($client, $service, $slot, excludeAppointmentId: null);

            $queueNumber = $this->nextQueueNumber($slot->service_window_id, $slot->slot_date);

            $appointment = Appointment::create([
                'reference_number' => $this->generateReferenceNumber(),
                'user_id' => $client->id,
                'service_id' => $service->id,
                'time_slot_id' => $slot->id,
                'service_window_id' => $slot->service_window_id,
                'status' => AppointmentStatus::Confirmed,
                'source' => AppointmentSource::Online,
                'scheduled_at' => $slot->start_at,
                'queue_number' => $queueNumber,
                'queue_date' => $slot->slot_date->toDateString(),
                'claims_senior_citizen' => (bool) ($claims['claims_senior_citizen'] ?? false),
                'claims_pwd' => (bool) ($claims['claims_pwd'] ?? false),
                'claims_pregnant' => (bool) ($claims['claims_pregnant'] ?? false),
                'priority_reference' => $claims['priority_reference'] ?? null,
                'client_note' => $claims['client_note'] ?? null,
                'confirmed_at' => now(),
            ]);

            // The capacity claim and the appointment row commit together.
            $slot->increment('booked_count');

            $this->recordStatus($appointment, null, AppointmentStatus::Confirmed, 'Booking confirmed online');

            AuditLog::record('appointment.booked', $appointment, [
                'slot_id' => $slot->id,
                'queue_number' => $queueNumber,
            ]);

            return $appointment->fresh(['service', 'timeSlot', 'window']);
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /* Reschedule */
    /* ------------------------------------------------------------------ */

    /**
     * Move an existing appointment to a different slot.
     *
     * The old slot's capacity is released and the new one claimed within one
     * transaction, so a client swapping slots cannot temporarily exceed either
     * slot's capacity.
     *
     * @throws SlotUnavailableException|AppointmentConflictException
     */
    public function reschedule(Appointment $appointment, int $newTimeSlotId): Appointment
    {
        if (! in_array($appointment->status, [
            AppointmentStatus::Pending,
            AppointmentStatus::Confirmed,
        ], true)) {
            throw AppointmentConflictException::notReschedulable($appointment);
        }

        return DB::transaction(function () use ($appointment, $newTimeSlotId) {
            $newSlot = TimeSlot::whereKey($newTimeSlotId)->lockForUpdate()->first();

            if (! $newSlot) {
                throw SlotUnavailableException::notFound();
            }

            if ((int) $newSlot->service_id !== (int) $appointment->service_id) {
                throw SlotUnavailableException::mismatchedService();
            }

            $this->assertSlotIsBookable($newSlot);

            // Exclude this appointment from its own conflict check, otherwise
            // rescheduling would always collide with itself.
            $this->assertNoConflict(
                $appointment->user,
                $appointment->service,
                $newSlot,
                excludeAppointmentId: $appointment->id,
            );

            $from = $appointment->status;

            $previousSlot = TimeSlot::whereKey($appointment->time_slot_id)->lockForUpdate()->first();

            $appointment->update([
                'time_slot_id' => $newSlot->id,
                'service_window_id' => $newSlot->service_window_id,
                'scheduled_at' => $newSlot->start_at,
                'queue_date' => $newSlot->slot_date->toDateString(),
                'queue_number' => $this->nextQueueNumber($newSlot->service_window_id, $newSlot->slot_date),
            ]);

            $newSlot->increment('booked_count');

            // Only release the old slot's place once the new one is secured.
            //
            // Scoped through the query builder on purpose: calling where() on a
            // model *instance* starts an unconstrained query and would decrement
            // booked_count on every slot in the table.
            if ($previousSlot && $previousSlot->id !== $newSlot->id) {
                TimeSlot::whereKey($previousSlot->id)
                    ->where('booked_count', '>', 0)
                    ->decrement('booked_count');
            }

            $this->recordStatus($appointment, $from, $appointment->status, 'Appointment rescheduled');

            AuditLog::record('appointment.rescheduled', $appointment, [
                'from_slot_id' => $appointment->getOriginal('time_slot_id'),
                'to_slot_id' => $newSlot->id,
            ]);

            return $appointment->fresh(['service', 'timeSlot', 'window']);
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /* Cancellation */
    /* ------------------------------------------------------------------ */

    /**
     * Cancel an appointment and give its slot place back to other clients.
     */
    public function cancel(Appointment $appointment, ?string $reason = null, ?User $actor = null): Appointment
    {
        if ($appointment->status->isClosed() && $appointment->status !== AppointmentStatus::Completed) {
            // Already cancelled or a no-show: nothing to release.
            return $appointment;
        }

        return DB::transaction(function () use ($appointment, $reason, $actor) {
            $from = $appointment->status;

            // Lock the slot so the release cannot interleave with another booking.
            $slot = TimeSlot::whereKey($appointment->time_slot_id)->lockForUpdate()->first();

            $appointment->update([
                'status' => AppointmentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            if ($slot && $slot->booked_count > 0) {
                $slot->decrement('booked_count');
            }

            $this->recordStatus($appointment, $from, AppointmentStatus::Cancelled, $reason ?: 'Cancelled');

            AuditLog::record('appointment.cancelled', $appointment, [
                'reason' => $reason,
                'by' => $actor?->id,
            ]);

            return $appointment->fresh();
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /* Validation */
    /* ------------------------------------------------------------------ */

    /**
     * All reasons a slot cannot be booked, checked against a locked slot row.
     */
    private function assertSlotIsBookable(TimeSlot $slot): void
    {
        if (! $slot->is_open) {
            throw SlotUnavailableException::closed();
        }

        if ($slot->start_at->isPast()) {
            throw SlotUnavailableException::past();
        }

        if ($slot->slot_date->gt(today()->addDays((int) config('govqueue.booking_horizon_days')))) {
            throw SlotUnavailableException::outsideHorizon();
        }

        // Authoritative capacity check against the locked row.
        if ($slot->booked_count >= $slot->capacity) {
            throw SlotUnavailableException::full();
        }
    }

    /**
     * Reject a booking that overlaps something the client already holds.
     *
     * Two rules, both taken from the document's scope: a client may hold only
     * one live booking per service, and may not hold two appointments whose
     * time slots overlap.
     */
    private function assertNoConflict(
        User $client,
        Service $service,
        TimeSlot $slot,
        ?int $excludeAppointmentId,
    ): void {
        $live = Appointment::query()
            ->with('service')
            ->where('user_id', $client->id)
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
                AppointmentStatus::CheckedIn->value,
                AppointmentStatus::Serving->value,
            ])
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId));

        foreach ($live->get() as $existing) {
            if ((int) $existing->service_id === (int) $service->id) {
                throw AppointmentConflictException::duplicateService($existing);
            }

            if ($this->overlaps($existing, $slot)) {
                throw AppointmentConflictException::overlappingSlot($existing);
            }
        }
    }

    /**
     * Do two booked slots share any time?
     *
     * Compared on the scheduled window, not just the date, so a 09:00 and a
     * 10:00 booking on the same day are both allowed while 09:00 and 09:30 are not.
     */
    private function overlaps(Appointment $existing, TimeSlot $candidate): bool
    {
        if ($existing->scheduled_at === null) {
            return false;
        }

        $existingStart = $existing->scheduled_at;
        $existingEnd = $existing->timeSlot?->end_at
            ?? $existingStart->copy()->addMinutes((int) $existing->service?->duration_minutes);

        return $candidate->start_at->lt($existingEnd) && $candidate->end_at->gt($existingStart);
    }

    /* ------------------------------------------------------------------ */
    /* Numbers and audit trail */
    /* ------------------------------------------------------------------ */

    /**
     * Issue the next queue number for a window on a day.
     *
     * The counter row is locked (and created if this is the first booking of the
     * day) so two concurrent bookings serialise instead of colliding.
     */
    public function nextQueueNumber(int $windowId, $date): string
    {
        $dateString = Carbon::parse($date)->toDateString();

        $counter = QueueCounter::where('service_window_id', $windowId)
            ->whereDate('queue_date', $dateString)
            ->lockForUpdate()
            ->first();

        if (! $counter) {
            try {
                // First booking of the day. Rely on the unique index to reject a
                // racing insert rather than pre-checking, which would race too.
                $counter = QueueCounter::create([
                    'service_window_id' => $windowId,
                    'queue_date' => $dateString,
                    'last_number' => 1,
                ]);
            } catch (QueryException $e) {
                if (! $this->isDuplicateKey($e)) {
                    throw $e;
                }

                // Another request inserted the row first; take the lock on theirs.
                $counter = QueueCounter::where('service_window_id', $windowId)
                    ->whereDate('queue_date', $dateString)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
        } else {
            $counter->increment('last_number');
            $counter->refresh();
        }

        return $this->formatQueueNumber($counter->last_number);
    }

    private function formatQueueNumber(int $number): string
    {
        return 'A-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Human-friendly booking reference, unique per booking.
     */
    public function generateReferenceNumber(?Carbon $date = null): string
    {
        $date ??= Carbon::now();

        do {
            $reference = 'GQ-'.$date->format('ymd').'-'.Str::upper(Str::random(6));
        } while (Appointment::where('reference_number', $reference)->exists());

        return $reference;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true)
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Append to the ticket's audit trail.
     */
    public function recordStatus(
        Appointment $appointment,
        ?AppointmentStatus $from,
        AppointmentStatus $to,
        ?string $note = null,
        ?User $actor = null,
    ): void {
        QueueStatusHistory::create([
            'appointment_id' => $appointment->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name ?? ($from === null ? 'System' : 'Client'),
            'note' => $note,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers for the booking screens */
    /* ------------------------------------------------------------------ */

    /**
     * Priority categories a client is entitled to *offer* on a booking form.
     *
     * Derived from the profile where possible; PWD and pregnant are always
     * claimable because they are per-transaction. None of these grant
     * precedence until staff verify - see the stated project limitation.
     *
     * @return array<string, array{label:string, basis:string, requires_reference:bool, available:bool}>
     */
    public function claimablePriorities(User $client): array
    {
        return [
            PriorityType::SeniorCitizen->value => [
                'label' => 'Senior citizen (60+)',
                'basis' => PriorityType::SeniorCitizen->basis(),
                // The date of birth already proves this, so no reference needed.
                'requires_reference' => false,
                'available' => $client->claims_senior_citizen,
            ],
            PriorityType::Pwd->value => [
                'label' => 'Person with disability',
                'basis' => PriorityType::Pwd->basis(),
                'requires_reference' => true,
                'available' => true,
            ],
            PriorityType::Pregnant->value => [
                'label' => 'Pregnant client',
                'basis' => PriorityType::Pregnant->basis(),
                'requires_reference' => true,
                'available' => true,
            ],
        ];
    }

    /**
     * Expand recurring templates so a service always has bookable slots.
     *
     * Called when the booking screen finds no generated slots at all, which can
     * happen on a fresh database where the nightly job has not run yet.
     */
    public function ensureSlotsExist(Service $service, int $days = 14): int
    {
        $generated = app(SlotGeneratorService::class)->generate(
            today(),
            today()->addDays(min($days, (int) config('govqueue.booking_horizon_days'))),
        );

        return $generated['created'];
    }
}
