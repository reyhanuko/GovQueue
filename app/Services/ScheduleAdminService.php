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

    public function deleteWindow(ServiceWindow $window): void
    {
        // Order matters: report the most likely blocker first.
        $serviceCount = $window->services()->count();

        if ($serviceCount > 0) {
            $this->block('window.delete_blocked', $window, [
                'services_attached' => $serviceCount,
            ], AdminActionBlockedException::windowHasServices($serviceCount, $window->name));
        }

        $staffCount = $window->staff()->count();

        if ($staffCount > 0) {
            $this->block('window.delete_blocked', $window, [
                'staff_attached' => $staffCount,
            ], AdminActionBlockedException::windowHasStaff($staffCount, $window->name));
        }

        // time_slots.service_window_id cascades too, but with no services there
        // should be none; checked so a stale row cannot silently vanish.
        if ($window->timeSlots()->exists()) {
            $this->block('window.delete_blocked', $window, [
                'reason' => 'generated_time_slots',
            ], new AdminActionBlockedException(
                "Cannot delete {$window->name} because it still has generated time slots.",
                'Regenerate or close the remaining slots first.',
            ));
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
        $appointmentCount = $service->appointments()->count();

        if ($appointmentCount > 0) {
            $this->block('service.delete_blocked', $service, [
                'appointments_referencing' => $appointmentCount,
            ], AdminActionBlockedException::serviceHasAppointments($appointmentCount, $service->name));
        }

        // With no appointments at all there can be nothing booked, but a slot
        // still left open would be a slot a client could grab for a service that
        // is about to vanish.
        $openSlots = $service->timeSlots()
            ->where('is_open', true)
            ->where('start_at', '>=', now())
            ->count();

        if ($openSlots > 0) {
            $this->block('service.delete_blocked', $service, [
                'open_slots_remaining' => $openSlots,
            ], AdminActionBlockedException::serviceStillHasOpenSlots($service->name, $openSlots));
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
    private function assertTemplateTimesAreUsable(array $data): void
    {
        $label = $data['name'] ?? 'this template';

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

        $slot->update(['capacity' => $capacity, 'is_open' => $isOpen]);

        AuditLog::record('time_slot.updated', $slot, [
            'date' => $slot->slot_date->toDateString(),
            'start_time' => $slot->start_time,
            'service' => $slot->service?->name,
            'before' => $before,
            'after' => ['capacity' => $slot->capacity, 'is_open' => $slot->is_open],
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
