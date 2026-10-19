<?php

namespace App\Services;

use App\Exceptions\AdminActionBlockedException;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Administrator-managed configuration: windows, services, recurring slot
 * templates, holidays, and per-slot capacity overrides.
 *
 * Every rule that protects data clients already depend on lives here rather
 * than in the controllers, so the checks are in one reviewable place and cannot
 * be skipped by adding another entry point. Each mutation writes an audit entry.
 *
 * Two schema facts drive most of the design:
 *
 *  - appointments.service_id, time_slot_id and service_window_id are all
 *    restrictOnDelete. Deleting something clients have booked raises a raw
 *    integrity error deep in MySQL, so the checks below run first and say
 *    something useful instead.
 *  - services.service_window_id is cascadeOnDelete. Deleting a window would
 *    silently remove every service in it, so a window with services is never
 *    deletable - deactivation is the intended route.
 */
class ScheduleAdminService
{
    /* ------------------------------------------------------------------ */
    /* Service windows */
    /* ------------------------------------------------------------------ */

    public function createWindow(array $data): ServiceWindow
    {
        $window = ServiceWindow::create($data);

        AuditLog::record('window.created', $window, ['name' => $window->name, 'code' => $window->code]);

        return $window;
    }

    public function updateWindow(ServiceWindow $window, array $data): ServiceWindow
    {
        $before = $window->only(['name', 'code', 'avg_service_minutes', 'is_active']);

        $window->update($data);

        AuditLog::record('window.updated', $window, [
            'before' => $before,
            'after' => $window->only(['name', 'code', 'avg_service_minutes', 'is_active']),
        ]);

        return $window;
    }

    /**
     * Why this window cannot be deleted, or null if it can.
     *
     * The screen and the guard both ask this. They used to disagree, which is
     * exactly what was reported: the confirm dialog promised that the window
     * "and its services will be removed", and the request then failed with "4
     * services still assigned". Two copies of the rule, one of them sitting in a
     * browser template, and the copy a client reads before committing was the one
     * that could not be trusted.
     *
     * Order matters: report the most likely blocker first.
     */
    public function deletionBlockerForWindow(ServiceWindow $window): ?AdminActionBlockedException
    {
        $serviceCount = $window->services()->count();

        if ($serviceCount > 0) {
            return AdminActionBlockedException::windowHasServices($serviceCount, $window->name);
        }

        $staffCount = $window->staff()->count();

        if ($staffCount > 0) {
            return AdminActionBlockedException::windowHasStaff($staffCount, $window->name);
        }

        // time_slots.service_window_id cascades too, but with no services there
        // should be none; checked so a stale row cannot silently vanish.
        if ($window->timeSlots()->exists()) {
            return AdminActionBlockedException::windowHasGeneratedSlots($window->name);
        }

        return null;
    }

    /**
     * Why this service cannot be deleted, or null if it can.
     *
     * Single source of truth, for the reason given on deletionBlockerForWindow().
     */
    public function deletionBlockerForService(Service $service): ?AdminActionBlockedException
    {
        $appointmentCount = $service->appointments()->count();

        if ($appointmentCount > 0) {
            return AdminActionBlockedException::serviceHasAppointments($appointmentCount, $service->name);
        }

        // With no appointments at all there can be nothing booked, but a slot
        // still left open would be a slot a client could grab for a service that
        // is about to vanish.
        $openSlots = $service->timeSlots()
            ->where('is_open', true)
            ->where('start_at', '>=', now())
            ->count();

        if ($openSlots > 0) {
            return AdminActionBlockedException::serviceStillHasOpenSlots($service->name, $openSlots);
        }

        return null;
    }

    public function deleteWindow(ServiceWindow $window): void
    {
        if ($blocker = $this->deletionBlockerForWindow($window)) {
            $this->block('window.delete_blocked', $window, $blocker->context, $blocker);
        }

        AuditLog::record('window.deleted', $window, ['name' => $window->name, 'code' => $window->code]);

        $window->delete();
    }

    /**
     * Refuse an administrator action, and record the attempt.
     *
     * A refused action is worth keeping: someone repeatedly trying to delete a
     * booked service, or to demote the last administrator, is exactly the pattern
     * an audit trail exists to surface. Without this the trail would only show
     * what succeeded, which reads as an all-clear.
     *
     * @param  array<string, mixed>  $payload
     */
    private function block(
        string $action,
        ?Model $subject,
        array $payload,
        AdminActionBlockedException $exception,
    ): never {
        AuditLog::record($action, $subject, [
            ...$payload,
            'blocked_reason' => $exception->getMessage(),
        ]);

        throw $exception;
    }

    /* ------------------------------------------------------------------ */
    /* Services */
    /* ------------------------------------------------------------------ */

    public function createService(array $data): Service
    {
        $service = Service::create($data);

        AuditLog::record('service.created', $service, [
            'name' => $service->name,
            'code' => $service->code,
        ]);

        return $service;
    }

    /**
     * Update a service, keeping derived rows consistent.
     *
     * Moving a service to a different window has to carry its concrete slots with
     * it. appointments.service_window_id is what the staff console and the lobby
     * display actually group by, and a slot left pointing at the old window would
     * keep appearing on the old window's board while the service is advertised
     * under the new one - a queue nobody is working.
     */
    public function updateService(Service $service, array $data): Service
    {
        $before = $service->only(['name', 'code', 'fee', 'duration_minutes', 'is_active', 'service_window_id']);

        $oldWindowId = $service->service_window_id;
        $newWindowId = array_key_exists('service_window_id', $data)
            ? (int) $data['service_window_id']
            : $oldWindowId;

        $service->update($data);

        if ($newWindowId !== (int) $oldWindowId) {
            $this->moveServiceSlotsToWindow($service, $newWindowId);
        }

        AuditLog::record('service.updated', $service, [
            'before' => $before,
            'after' => $service->only(['name', 'code', 'fee', 'duration_minutes', 'is_active', 'service_window_id']),
            'window_moved' => $newWindowId !== (int) $oldWindowId,
        ]);

        return $service;
    }

    /**
     * Re-point a service's slots, and any upcoming appointments, at the new window.
     *
     * Past appointments are left alone: they record what actually happened, and
     * rewriting history would put a completed transaction on a window it was
     * never served at.
     */
    private function moveServiceSlotsToWindow(Service $service, int $newWindowId): void
    {
        DB::transaction(function () use ($service, $newWindowId) {
            TimeSlot::where('service_id', $service->id)
                ->update(['service_window_id' => $newWindowId]);

            Appointment::where('service_id', $service->id)
                ->where('service_window_id', '!=', $newWindowId)
                ->whereIn('status', ['pending', 'confirmed', 'checked_in'])
                ->update(['service_window_id' => $newWindowId]);
        });
    }

    public function deleteService(Service $service): void
    {
        if ($blocker = $this->deletionBlockerForService($service)) {
            $this->block('service.delete_blocked', $service, $blocker->context, $blocker);
        }

        AuditLog::record('service.deleted', $service, ['name' => $service->name, 'code' => $service->code]);

        // slot_templates and time_slots both cascade from services.
        $service->delete();
    }

    /* ------------------------------------------------------------------ */
    /* Slot templates */
    /* ------------------------------------------------------------------ */

    public function createTemplate(array $data): SlotTemplate
    {
        $this->assertTemplateTimesAreUsable($data);

        $template = SlotTemplate::create($data);

        AuditLog::record('slot_template.created', $template, [
            'service' => $template->service?->name,
            'day' => $template->dayName(),
            'window' => $template->start_time.'-'.$template->end_time,
            'capacity' => $template->capacity,
        ]);

        return $template;
    }

    public function updateTemplate(SlotTemplate $template, array $data): SlotTemplate
    {
        $this->assertTemplateTimesAreUsable([...$template->only([
            'start_time', 'end_time', 'slot_minutes',
        ]), ...$data]);

        $template->update($data);

        AuditLog::record('slot_template.updated', $template, [
            'service' => $template->service?->name,
            'day' => $template->dayName(),
            'window' => $template->start_time.'-'.$template->end_time,
            'capacity' => $template->capacity,
            'active' => $template->is_active,
        ]);

        return $template;
    }

    public function deleteTemplate(SlotTemplate $template): void
    {
        AuditLog::record('slot_template.deleted', $template, [
            'service' => $template->service?->name,
            'day' => $template->dayName(),
            'window' => $template->start_time.'-'.$template->end_time,
        ]);

        $template->delete();
    }

    /**
     * A template that cannot produce a single slot is a silent dead end.
     *
     * The generator stops before emitting a slot that would overrun the window,
     * so a 30-minute slot inside a 20-minute window yields nothing at all and
     * the service silently has no availability.
     */
    /**
     * Refuse a template for a day the office is closed.
     *
     * Decision D3: strict open-day validation, Monday to Friday by default.
     *
     * Checked on create AND on update, because a template that was legitimate when
     * it was saved can become illegitimate when the open-day setting changes, and
     * an update that only touches the start time should not quietly leave a
     * Saturday schedule in place.
     *
     * A block rather than a warning. A template that generates no bookable slots
     * is not a configuration choice, it is a schedule that lies about when the
     * office opens - and F-08(e) is exactly that having already happened here.
     *
     * The hint names the setting rather than suggesting the template be deleted,
     * because an LGU holding a Saturday market day needs a Saturday schedule and
     * needs it to be legal.
     */
    private function assertTemplateIsOnAnOpenDay(array $data, string $label): void
    {
        if (! array_key_exists('day_of_week', $data)) {
            return;
        }

        $openDays = (array) config('govqueue.open_days', [1, 2, 3, 4, 5]);

        // An empty list would block every day, which is never what an empty
        // configuration means. Treated as "no restriction" instead.
        if ($openDays === []) {
            return;
        }

        $dayOfWeek = (int) $data['day_of_week'];

        if (in_array($dayOfWeek, array_map('intval', $openDays), true)) {
            return;
        }

        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $dayName = $names[$dayOfWeek] ?? 'That day';

        $this->block('slot_template.rejected', null, [
            'reason' => 'closed_day',
            'day_of_week' => $dayOfWeek,
        ], AdminActionBlockedException::templateOnClosedDay(
            $label,
            $dayName,
            $this->describeOpenDays($openDays),
        ));
    }

    /** "Monday to Friday", or the actual list when it is not the usual week. */
    private function describeOpenDays(array $openDays): string
    {
        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $openDays = array_map('intval', $openDays);
        sort($openDays);

        if ($openDays === [1, 2, 3, 4, 5]) {
            return 'Monday to Friday';
        }

        return implode(', ', array_map(fn ($d) => $names[$d] ?? (string) $d, $openDays));
    }

    private function assertTemplateTimesAreUsable(array $data): void
    {
        $label = $data['name'] ?? 'this template';

        $this->assertTemplateIsOnAnOpenDay($data, $label);

        $start = Carbon::parse('2000-01-01 '.$data['start_time']);
        $end = Carbon::parse('2000-01-01 '.$data['end_time']);

        if ($end->lte($start)) {
            $this->block('slot_template.rejected', null, [
                'reason' => 'end_not_after_start',
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
            ], AdminActionBlockedException::templateRangeInverted($label));
        }

        $windowMinutes = (int) $start->diffInMinutes($end);

        if ((int) $data['slot_minutes'] > $windowMinutes) {
            $this->block('slot_template.rejected', null, [
                'reason' => 'slot_longer_than_window',
                'window_minutes' => $windowMinutes,
                'slot_minutes' => (int) $data['slot_minutes'],
            ], AdminActionBlockedException::templateTooShortForSlot(
                $label,
                $windowMinutes,
                (int) $data['slot_minutes'],
            ));
        }

        if (! empty($data['effective_to']) && ! empty($data['effective_from'])) {
            if (Carbon::parse($data['effective_to'])->lt(Carbon::parse($data['effective_from']))) {
                $this->block('slot_template.rejected', null, [
                    'reason' => 'effective_dates_inverted',
                    'effective_from' => $data['effective_from'],
                    'effective_to' => $data['effective_to'],
                ], AdminActionBlockedException::effectiveDatesInverted($label));
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Holidays */
    /* ------------------------------------------------------------------ */

    public function createHoliday(array $data): Holiday
    {
        $holiday = Holiday::create($data);

        // Declaring a closure changes what every booking screen is allowed to show,
        // and that answer is cached. Invalidating here rather than in the
        // controller means a holiday created from a console, a seeder, or a future
        // admin screen cannot leave the catalogue advertising a shut day.
        app(SlotAvailabilityService::class)->forgetClosedDates();

        AuditLog::record('holiday.created', $holiday, [
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
        ]);

        return $holiday;
    }

    public function deleteHoliday(Holiday $holiday): void
    {
        AuditLog::record('holiday.deleted', $holiday, [
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
        ]);

        $holiday->delete();

        // Same reason as above, in reverse: the date is bookable again immediately,
        // and a stale cache would keep hiding it from clients who want it.
        app(SlotAvailabilityService::class)->forgetClosedDates();
    }

    /**
     * Clients holding a booking on a day that is about to be declared a holiday.
     *
     * Declaring a holiday hides those slots from every booking screen, so clients
     * who already booked would simply stop seeing them and could not tell whether
     * their appointment had been cancelled. They are not cancelled automatically -
     * that is a decision for a human - but staff are told exactly who is affected.
     *
     * @return Collection<int, Appointment>
     */
    public function clientsAffectedByHoliday(string $date): Collection
    {
        return Appointment::query()
            ->with(['user', 'service', 'timeSlot'])
            ->whereDate('scheduled_at', $date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Cancel every appointment a closure catches, and tell each client why.
     *
     * Decision D8: a closure hides the slots, and the remaining question is what
     * happens to the people already holding one. Flagging alone leaves an
     * administrator to contact or rebook every one of them by hand, which is
     * exactly the work that does not get done. Cancelling in bulk, with a
     * notification, is the industry standard and it is what an office with a
     * declared holiday actually needs.
     *
     * Every cancellation goes through BookingService::cancel(), deliberately
     * rather than a batch UPDATE. That is what guarantees, per appointment: the
     * slot's place is returned, the status history is written, the audit entry
     * records who did it, the lobby board is re-announced, and the client is
     * notified subject to their own channel preferences. A bulk update would
     * have been four lines and would have silently skipped all five.
     *
     * Resolved through the container rather than injected, because BookingService
     * is built the same way and a constructor dependency between them would be a
     * cycle waiting to happen.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return array{cancelled:int, skipped:int} skipped counts rows already closed
     */
    public function cancelAppointmentsForClosure(Collection $appointments, string $reason, ?User $actor): array
    {
        $booking = app(BookingService::class);

        $cancelled = 0;
        $skipped = 0;

        foreach ($appointments as $appointment) {
            // cancel() is already a no-op on a closed appointment, but counting
            // it separately keeps the number an administrator reads honest: it
            // says how many people were actually affected.
            if ($appointment->status->isClosed()) {
                $skipped++;

                continue;
            }

            $booking->cancel($appointment, $reason, $actor);
            $cancelled++;
        }

        return ['cancelled' => $cancelled, 'skipped' => $skipped];
    }

    /* ------------------------------------------------------------------ */
    /* Individual slot overrides */
    /* ------------------------------------------------------------------ */

    /**
     * Override one slot's capacity or open/closed state.
     *
     * Capacity is floored at booked_count. Below that the slot is permanently
     * over-subscribed, `isBookable()` goes false for everyone including the
     * clients already in it, and remainingCapacity() goes negative - so the
     * clients already booked would silently vanish from the slot they hold.
     */
    public function updateSlot(TimeSlot $slot, int $capacity, bool $isOpen): TimeSlot
    {
        if ($capacity < (int) $slot->booked_count) {
            $this->block('time_slot.capacity_blocked', $slot, [
                'requested_capacity' => $capacity,
                'booked_count' => (int) $slot->booked_count,
            ], AdminActionBlockedException::capacityBelowBooked(
                (int) $slot->booked_count,
                $slot->slot_date->format('D, M d').' at '.$slot->formatTime($slot->start_time),
            ));
        }

        if ($capacity < 1 && $isOpen) {
            $this->block('time_slot.capacity_blocked', $slot, [
                'requested_capacity' => $capacity,
                'reason' => 'open_with_zero_capacity',
            ], new AdminActionBlockedException(
                'Cannot leave a slot open with no capacity.',
                'Give it at least one place, or close the slot instead.',
            ));
        }

        $before = $slot->only(['capacity', 'is_open', 'booked_count']);

        /*
         * A capacity set by hand is marked as such, and the flag is sticky.
         *
         * The nightly generator rewrites capacity from the template, so without
         * this an override survived only until 01:30 - the administrator was
         * told "Capacity updated" and the number was back to the template's by
         * morning. Sticky rather than recomputed: once somebody has decided this
         * slot is different from its template, it stays different even if a later
         * save happens to type the template's own value back in.
         *
         * Only set when the capacity actually changed, so merely toggling a slot
         * open and closed does not freeze it against its template.
         */
        $capacityChanged = (int) $slot->capacity !== $capacity;

        $slot->update([
            'capacity' => $capacity,
            'is_open' => $isOpen,
            'capacity_is_overridden' => $slot->capacity_is_overridden || $capacityChanged,
        ]);

        AuditLog::record('time_slot.updated', $slot, [
            'date' => $slot->slot_date->toDateString(),
            'start_time' => $slot->start_time,
            'service' => $slot->service?->name,
            'before' => $before,
            'after' => [
                'capacity' => $slot->capacity,
                'is_open' => $slot->is_open,
                // Recorded because it changes how the nightly generator will treat
                // this slot from now on, and nothing else about the row does.
                'capacity_is_overridden' => (bool) $slot->capacity_is_overridden,
            ],
        ]);

        return $slot;
    }

    public function deleteSlot(TimeSlot $slot): void
    {
        $booked = $slot->appointments()->count();

        if ($booked > 0) {
            $this->block('time_slot.delete_blocked', $slot, [
                'appointments_in_slot' => $booked,
            ], AdminActionBlockedException::slotHasAppointments(
                $booked,
                $slot->slot_date->format('D, M d').' at '.$slot->formatTime($slot->start_time),
            ));
        }

        AuditLog::record('time_slot.deleted', $slot, [
            'date' => $slot->slot_date->toDateString(),
            'start_time' => $slot->start_time,
        ]);

        $slot->delete();
    }

    /* ------------------------------------------------------------------ */
    /* Generation */
    /* ------------------------------------------------------------------ */

    /**
     * Expand templates into concrete slots across the booking horizon.
     *
     * @return array{created:int, skipped:int}
     */
    public function regenerateSlots(?int $days = null): array
    {
        $days = $days ?? (int) config('govqueue.booking_horizon_days');

        $result = app(SlotGeneratorService::class)->generate(today(), today()->addDays($days));

        AuditLog::record('time_slots.regenerated', null, [
            'from' => today()->toDateString(),
            'to' => today()->addDays($days)->toDateString(),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);

        return $result;
    }

    /**
     * Windows that still have no concrete slots in the horizon, i.e. an
     * administrator configured a service but never gave it a template.
     *
     * @return Collection<int, Service>
     */
    public function servicesMissingTemplates(?int $days = null): Collection
    {
        $days = $days ?? (int) config('govqueue.booking_horizon_days');

        return Service::query()
            ->active()
            ->with('window')
            ->whereDoesntHave('slotTemplates', fn ($q) => $q->where('is_active', true))
            ->whereDoesntHave('timeSlots', fn ($q) => $q
                ->whereDate('slot_date', '>=', today()->toDateString())
                ->whereDate('slot_date', '<=', today()->addDays($days)->toDateString()))
            ->orderBy('name')
            ->get();
    }

    /**
     * Staff counts per window, for the dashboard.
     */
    public function windowStaffCounts(): Collection
    {
        return User::query()
            ->where('role', 'staff')
            ->whereNotNull('service_window_id')
            ->selectRaw('service_window_id, COUNT(*) as total')
            ->groupBy('service_window_id')
            ->pluck('total', 'service_window_id');
    }
}
