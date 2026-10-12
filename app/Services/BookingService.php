<?php

namespace App\Services;

use App\Contracts\QueueAnnouncer;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\PriorityType;
use App\Enums\UserRole;
use App\Exceptions\AppointmentConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\QueueCounter;
use App\Models\QueueStatusHistory;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

            $fresh = $appointment->fresh(['service', 'timeSlot', 'window', 'user']);

            // The window's board changed (a new ticket in the day's queue).
            // Confirmation is dispatched after commit and queued, so a booking is
            // never held up by - or broken by - the mail server.
            DB::afterCommit(function () use ($fresh) {
                app(QueueAnnouncer::class)->appointmentChanged($fresh);

                if (config('govqueue.notifications.enabled', true)) {
                    app(ClientNotificationService::class)->confirmation($fresh);
                }
            });

            return $fresh;
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
        /*
         * Re-read and re-lock the appointment inside the transaction.
         *
         * The status precondition used to be checked here, before the
         * transaction opened, and the row was never locked at all. Two requests
         * - a double-submitted form, or a retry behind the booking throttle -
         * could therefore both pass the check and both proceed: each incremented
         * the new slot, and each decremented the old one. The old slot's
         * `where booked_count > 0` clamp hides the symptom until it hits zero,
         * by which point the count is already wrong.
         *
         * The transaction's retry count does not help: these are not deadlocks.
         *
         * Nothing is trusted from the caller - the status is read fresh, under a
         * lock, so the second request sees what the first one committed.
         */
        return DB::transaction(function () use ($appointment, $newTimeSlotId) {
            $locked = Appointment::whereKey($appointment->getKey())->lockForUpdate()->first();

            if (! $locked) {
                throw AppointmentConflictException::notReschedulable($appointment);
            }

            if (! in_array($locked->status, [
                AppointmentStatus::Pending,
                AppointmentStatus::Confirmed,
            ], true)) {
                throw AppointmentConflictException::notReschedulable($locked);
            }

            $appointment = $locked;

            // Rescheduling into the slot the client already holds is a no-op, not
            // a move. Treated as one, it would increment booked_count and then
            // skip the matching decrement, permanently inflating the slot.
            if ((int) $appointment->time_slot_id === (int) $newTimeSlotId) {
                return $appointment->fresh(['service', 'timeSlot', 'window', 'user']);
            }

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

            // Captured before the update overwrites them, so the window that was
            // left behind can be told its board changed.
            $previousWindowId = (int) $appointment->service_window_id;
            $previousQueueDate = $appointment->queue_date?->toDateString();

            // Human-readable "from" for the reschedule notification.
            $previousSlotLabel = $previousSlot
                ? $previousSlot->slot_date->format('D d M').', '.$previousSlot->timeRangeLabel()
                : null;

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
            // booked_count on every slot in the table. The id comparison is now
            // redundant - the same-slot case returns earlier - but it costs
            // nothing and keeps the invariant local to the release.
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

            $fresh = $appointment->fresh(['service', 'timeSlot', 'window', 'user']);

            // Two windows can be affected: the one left and the one joined.
            DB::afterCommit(function () use ($fresh, $previousWindowId, $previousQueueDate, $previousSlotLabel) {
                $announcer = app(QueueAnnouncer::class);
                $announcer->appointmentChanged($fresh);

                if ($previousWindowId !== (int) $fresh->service_window_id) {
                    $announcer->queueChanged(
                        ServiceWindow::findOrFail($previousWindowId),
                        $previousQueueDate,
                    );
                }

                if (config('govqueue.notifications.enabled', true)) {
                    app(ClientNotificationService::class)->rescheduled($fresh, $previousSlotLabel);
                }
            });

            return $fresh;
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
        /*
         * Any terminal state is a no-op.
         *
         * The old condition was `isClosed() && status !== Completed`, which made
         * Completed the one closed state that fell through - so cancelling a
         * completed appointment rewrote it to Cancelled, bypassed the transition
         * rules, and decremented a slot that complete() had already released. The
         * `!== Completed` clause read as an exception someone had intended; it is
         * the opposite of one.
         *
         * Unreachable today - the only caller pre-checks isClosed() - but a guard
         * that reads as deliberate is a guard the next caller will trust.
         */
        if ($appointment->status->isClosed()) {
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

            /*
             * A walk-in never incremented this counter, so cancelling one must
             * not decrement it either - otherwise the slot loses a place that
             * belonged to an online booking. Mirrors QueueService::releaseSlot(),
             * which has the same rule for the same reason.
             */
            if ($slot
                && $slot->booked_count > 0
                && $appointment->source !== AppointmentSource::WalkIn) {
                $slot->decrement('booked_count');
            }

            $this->recordStatus($appointment, $from, AppointmentStatus::Cancelled, $reason ?: 'Cancelled');

            AuditLog::record('appointment.cancelled', $appointment, [
                'reason' => $reason,
                'by' => $actor?->id,
            ]);

            $fresh = $appointment->fresh(['service', 'timeSlot', 'window', 'user']);

            DB::afterCommit(function () use ($fresh) {
                app(QueueAnnouncer::class)->appointmentChanged($fresh);

                if (config('govqueue.notifications.enabled', true)) {
                    app(ClientNotificationService::class)->cancelled($fresh);
                }
            });

            return $fresh;
        }, 3);
    }

    /* ------------------------------------------------------------------ */
    /* Walk-ins */
    /* ------------------------------------------------------------------ */

    /**
     * Issue a walk-in ticket at the counter.
     *
     * A walk-in holds no slot, so none of the slot machinery above applies: it
     * goes straight into today's queue for the service's window.
     *
     * The duplicate guard exists because staff taking the same name twice in a
     * row produced two live tickets (W-002 and W-003 for one person), and both
     * were then served. It matches on name OR contact, for the same service,
     * against tickets still waiting. $override exists because the guard is a
     * safety net, not a rule: two different people can share a surname, and a
     * member of staff dealing with that situation knows something the system
     * does not. The override is recorded in the audit trail either way.
     *
     * @param  array{name:string, email?:?string, phone?:?string, service_id:int, claims?:array<int,string>, priority_reference?:?string}  $data
     *
     * @throws AppointmentConflictException
     */
    public function createWalkIn(array $data, ?User $actor, bool $override = false): Appointment
    {
        $service = Service::with('window')->findOrFail($data['service_id']);

        if (! $service->window) {
            throw SlotUnavailableException::noWindow();
        }

        $slot = $this->currentSlotFor($service);

        if (! $override) {
            $existing = $this->findDuplicateWalkIn($service, $data);

            if ($existing) {
                throw AppointmentConflictException::duplicateWalkIn(
                    $existing['appointment'],
                    $existing['matched_on'],
                );
            }
        }

        /*
         * Claims recorded at the counter.
         *
         * Recorded, not granted. priority_verified_at is deliberately left null,
         * so a walk-in claiming to be a senior citizen is queued exactly like
         * anybody else until staff verify the claim - the same rule the client's
         * own claim follows. Setting it here would let one tick box decide who
         * is served next.
         *
         * Checked rather than filtered: silently dropping a category staff
         * believed they had recorded is worse than refusing it, because the
         * ticket is issued either way and the claim simply vanishes.
         */
        $requested = array_values((array) ($data['claims'] ?? []));
        $known = array_map(fn (PriorityType $type) => $type->value, PriorityType::cases());

        $unknown = array_values(array_diff($requested, $known));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'walkInClaims' => 'Not a priority category this office recognises: '
                    .implode(', ', $unknown).'.',
            ]);
        }

        $claims = array_values(array_filter(
            PriorityType::cases(),
            fn (PriorityType $type) => in_array($type->value, $requested, true),
        ));

        $claimFlags = [
            'claims_senior_citizen' => in_array(PriorityType::SeniorCitizen, $claims, true),
            'claims_pwd' => in_array(PriorityType::Pwd, $claims, true),
            'claims_pregnant' => in_array(PriorityType::Pregnant, $claims, true),
        ];

        $priorityReference = trim((string) ($data['priority_reference'] ?? '')) ?: null;

        /*
         * A PWD or pregnancy claim cannot be inferred from anything at the
         * counter, so it needs the same supporting reference a client's own
         * claim requires. Rejected rather than silently dropped: accepting the
         * claim without it would create an unverifiable record that still looks
         * as strong as one with evidence.
         */
        if ($claims !== []
            && ($claimFlags['claims_pwd'] || $claimFlags['claims_pregnant'])
            && blank($priorityReference)) {
            throw ValidationException::withMessages([
                'walkInPriorityReference' => 'Enter the PWD / medical / referral reference for this claim so it can be verified.',
            ]);
        }

        // A walk-in has no account of their own, so the client is matched on
        // what staff typed. An existing account with the same email or number is
        // reused where possible so the client can see the ticket and receive
        // queue notifications; otherwise a placeholder record is created.
        $client = $this->resolveWalkInClient($data);

        return DB::transaction(function () use (
            $client, $service, $actor, $override, $slot, $claimFlags, $priorityReference
        ) {
            /*
             * The queue number is issued INSIDE the transaction, not before it.
             *
             * nextWalkInNumber() reads the highest W- number in use with an
             * unlocked aggregate. Two counters pressing "issue walk-in" at the
             * same moment both read the same maximum and both wrote W-007, which
             * the lobby board then showed twice - the one thing a queue number
             * is not allowed to do.
             *
             * The counter row is taken for update first, which is what serialises
             * the two requests. This is the same lock nextQueueNumber() takes,
             * reached through the shared helper rather than a second copy of the
             * locking dance.
             *
             * The A- counter's own number is never touched by a walk-in: a
             * walk-in joins the same physical line but must not push an online
             * booking's number along. It is created here purely as a row to lock.
             */
            $this->lockQueueCounter($service->service_window_id, today()->toDateString());

            $queueNumber = $this->nextWalkInNumber($service->service_window_id, today()->toDateString());
            $appointment = Appointment::create([
                'reference_number' => $this->generateReferenceNumber(),
                'user_id' => $client->id,
                'service_id' => $service->id,
                // appointments.time_slot_id is NOT NULL, so a walk-in is filed
                // under the slot at the counter. scheduled_at stays null, which
                // is what marks the ticket as a walk-in everywhere else.
                'time_slot_id' => $slot->id,
                'service_window_id' => $service->service_window_id,
                'status' => AppointmentStatus::CheckedIn,
                'source' => AppointmentSource::WalkIn,
                'scheduled_at' => null,
                'queue_number' => $queueNumber,
                'queue_date' => today()->toDateString(),
                ...$claimFlags,
                'priority_reference' => $priorityReference,
                'confirmed_at' => now(),
                'checked_in_at' => now(),
            ]);

            $this->recordStatus(
                $appointment,
                null,
                AppointmentStatus::CheckedIn,
                'Walk-in issued at the counter',
                $actor,
            );

            AuditLog::record('appointment.walk_in_created', $appointment, [
                'queue_number' => $queueNumber,
                'duplicate_override' => $override,
                // Recorded as a claim. The audit trail is where somebody
                // checking later can see who recorded a priority category and
                // whether it was ever verified.
                'claims_priority' => array_values(array_filter(
                    array_keys($claimFlags),
                    fn (string $flag) => $claimFlags[$flag],
                )),
                'priority_reference' => $priorityReference,
            ]);

            $fresh = $appointment->fresh(['user', 'service', 'window']);

            DB::afterCommit(fn () => app(QueueAnnouncer::class)->appointmentChanged($fresh));

            return $fresh;
        }, 3);
    }

    /**
     * The slot a walk-in should be filed under: the one covering right now, or
     * the next one to start.
     *
     * appointments.time_slot_id is NOT NULL, so a walk-in has to sit in a slot.
     * This is bookkeeping only - a walk-in never claims capacity, so its
     * booked_count is not incremented - but it keeps the ticket attached to the
     * service's schedule rather than dangling.
     *
     * @throws SlotUnavailableException
     */
    private function currentSlotFor(Service $service): TimeSlot
    {
        $slot = TimeSlot::where('service_id', $service->id)
            ->whereDate('slot_date', today()->toDateString())
            ->orderByDesc('start_at')
            ->orderBy('start_at')
            ->get()
            ->first(fn (TimeSlot $s) => $s->start_at->isPast()) // the slot at the counter
            ?? TimeSlot::where('service_id', $service->id)
                ->whereDate('slot_date', today()->toDateString())
                ->where('start_at', '>', now())
                ->orderBy('start_at')
                ->first();

        if (! $slot) {
            throw SlotUnavailableException::noSlotsForWalkIn();
        }

        return $slot;
    }

    /**
     * The waiting walk-in this one looks like a duplicate of, if any.
     *
     * Only statuses that still hold a place in the line count: a ticket that has
     * been served, skipped past repeatedly, or closed is not a duplicate of
     * somebody turning up again.
     *
     * @return array{appointment: Appointment, matched_on: string}|null
     */
    private function findDuplicateWalkIn(Service $service, array $data): ?array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if ($name === '' && $phone === '' && $email === '') {
            return null;
        }

        $existing = Appointment::with('user')
            ->where('service_id', $service->id)
            ->whereIn('status', [
                AppointmentStatus::CheckedIn->value,
                AppointmentStatus::Skipped->value,
                AppointmentStatus::Confirmed->value,
                AppointmentStatus::Pending->value,
            ])
            ->whereHas('user', function ($q) use ($name, $phone, $email) {
                $q->where(function ($inner) use ($name, $phone, $email) {
                    if ($name !== '') {
                        $inner->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                    }

                    if ($phone !== '') {
                        $inner->orWhere('phone', $phone);
                    }

                    if ($email !== '') {
                        $inner->orWhere('email', $email);
                    }
                });
            })
            ->orderBy('id')
            ->first();

        if (! $existing) {
            return null;
        }

        // Which field matched decides the wording of the message, and contact
        // is the stronger signal, so it is checked first.
        $matchedOn = ($phone !== '' && $existing->user->phone === $phone) ? 'contact'
            : (($email !== '' && $existing->user->email === $email) ? 'contact' : 'name');

        return ['appointment' => $existing, 'matched_on' => $matchedOn];
    }

    /**
     * Find or create the client record behind a walk-in.
     *
     * Reusing an account matters: it is how the client gets the "you are being
     * called" notification and how they later cancel from their own list.
     */
    private function resolveWalkInClient(array $data): User
    {
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));

        if ($phone !== '' && ($user = User::where('phone', $phone)->first())) {
            return $user;
        }

        if ($email !== '' && ($user = User::where('email', $email)->first())) {
            return $user;
        }

        return User::create([
            'name' => trim((string) ($data['name'] ?? 'Walk-in client')),
            // No email of their own: the address is namespaced per walk-in so a
            // second unnamed client cannot collide with the first.
            'email' => $email !== ''
                ? $email
                : 'walkin-'.Str::lower(Str::random(12)).'@govqueue.invalid',
            'phone' => $phone !== '' ? $phone : null,
            'password' => Str::random(32),
            'role' => UserRole::Client,
            'barangay' => trim((string) ($data['barangay'] ?? '')) ?: 'Poblacion',
            // Staff cannot confirm a client's identity at the counter, so a
            // placeholder record never counts as priority.
            'claims_senior_citizen' => false,
        ]);
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
     *
     * When the row is created - or found lagging - it is seeded from the highest
     * queue number actually in use for that window and day. Without that,
     * appointments inserted outside this service (demo seed data, a manual
     * import) would be handed a number that is already on the public display.
     */
    public function nextQueueNumber(int $windowId, $date): string
    {
        $dateString = Carbon::parse($date)->toDateString();

        $counter = $this->lockQueueCounter($windowId, $dateString);

        if ($counter->wasRecentlyCreated) {
            // Seeded from the tickets already in use at creation, so the very
            // first booking of the day cannot land on a number an import or a
            // seed already put on the display.
            return $this->formatQueueNumber($counter->last_number);
        }

        // Self-heal a counter that has fallen behind the actual tickets.
        $highest = $this->highestQueueNumberInUse($windowId, $dateString);
        $next = max($counter->last_number, $highest) + 1;

        if ($next !== $counter->last_number) {
            $counter->update(['last_number' => $next]);
        } else {
            $counter->increment('last_number');
            $counter->refresh();
        }

        return $this->formatQueueNumber($counter->last_number);
    }

    /**
     * Take the row lock that serialises numbering for a window and day.
     *
     * Both numbering paths go through here - the online A- series and the
     * walk-in W- series - because the contention is on the same pair of keys.
     * Two counters cannot issue numbers for one window at the same time, whichever
     * series they are issuing from.
     *
     * Creating the row is the awkward part. A plain firstOrCreate() is not safe
     * here: the unique index on (service_window_id, queue_date) rejects the
     * second concurrent insert, and the loser got a duplicate-key error instead
     * of a queue number. So the insert is attempted and, on a duplicate, the
     * winner's row is locked instead.
     *
     * Must be called inside a transaction - a lockForUpdate() with nothing to
     * hold it is released immediately.
     */
    private function lockQueueCounter(int $windowId, string $dateString): QueueCounter
    {
        $counter = QueueCounter::where('service_window_id', $windowId)
            ->whereDate('queue_date', $dateString)
            ->lockForUpdate()
            ->first();

        if ($counter) {
            return $counter;
        }

        try {
            // Seeded from the tickets in use rather than from zero, so an
            // appointment inserted outside this service (demo seed data, a
            // manual import) cannot be handed a number already on the display.
            return QueueCounter::create([
                'service_window_id' => $windowId,
                'queue_date' => $dateString,
                'last_number' => $this->highestQueueNumberInUse($windowId, $dateString) + 1,
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            // Another request inserted it first. Take the lock on theirs so this
            // one waits rather than racing.
            $counter = QueueCounter::where('service_window_id', $windowId)
                ->whereDate('queue_date', $dateString)
                ->lockForUpdate()
                ->firstOrFail();

            $counter->increment('last_number');
            $counter->refresh();

            return $counter;
        }
    }

    /**
     * Highest numeric queue number already issued for a window on a day.
     *
     * Walk-in tickets use a W- prefix and are excluded: they sit in the same
     * physical line but do not consume the A- sequence.
     */
    private function highestQueueNumberInUse(int $windowId, string $dateString): int
    {
        return (int) Appointment::where('service_window_id', $windowId)
            ->whereDate('queue_date', $dateString)
            ->whereNotNull('queue_number')
            ->where('queue_number', 'like', 'A-%')
            ->get()
            ->map(fn (Appointment $a) => (int) substr((string) $a->queue_number, 2))
            ->max();
    }

    private function formatQueueNumber(int $number): string
    {
        return 'A-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Next walk-in number for a window on a day, in the W- series.
     *
     * Deliberately separate from nextQueueNumber(): a walk-in joins the same
     * physical line but does not consume an A- number, so a client who booked
     * online is never pushed down by somebody arriving at the counter. Derived
     * from the tickets actually in use rather than a counter row, because the
     * counter row tracks the A- series only.
     */
    public function nextWalkInNumber(int $windowId, string $date): string
    {
        $dateString = Carbon::parse($date)->toDateString();

        $highest = (int) Appointment::where('service_window_id', $windowId)
            ->whereDate('queue_date', $dateString)
            ->where('queue_number', 'like', 'W-%')
            ->get()
            ->map(fn (Appointment $a) => (int) substr((string) $a->queue_number, 2))
            ->max();

        return 'W-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
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
