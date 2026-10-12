<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AdminActionBlockedException;
use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Services\ScheduleAdminService;
use App\Services\StaffingCapacityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Day-by-day capacity control over concrete time slots.
 *
 * This is the escape hatch an office needs on the day: close a slot, add a place
 * because a second clerk is opening, or drop a slot that will not be served.
 * Capacity can never go below the number of clients already booked - see
 * ScheduleAdminService::updateSlot().
 */
class TimeSlotController extends Controller
{
    public function __construct(
        private ScheduleAdminService $admin,
        private StaffingCapacityService $capacity,
    ) {}

    public function index(Request $request): View
    {
        /*
         * Past dates are allowed here on purpose. Reporting and reconciling need
         * to look back at what was actually served, so a past day is rendered -
         * read-only. What is refused is a date far enough in the past that there
         * is nothing there: an unbounded date box let an administrator wander
         * back years and be met by an empty page that reads like data loss.
         */
        $date = $request->date('date') ?? today();

        if ($date->lt($this->earliestManageableDate())) {
            $date = today();
        }

        $slots = TimeSlot::query()
            ->with(['service', 'window'])
            ->withCount('appointments')
            ->whereDate('slot_date', $date->toDateString())
            ->orderBy('service_window_id')
            ->orderBy('start_time')
            ->get()
            ->groupBy('service_window_id');

        $totalBooked = $slots->flatten(1)->sum('booked_count');
        $totalCapacity = $slots->flatten(1)->sum('capacity');

        return view('admin.slots.index', [
            'date' => $date,
            'slotsByWindow' => $slots,
            'totalBooked' => $totalBooked,
            'totalCapacity' => $totalCapacity,
            'isHoliday' => Holiday::isClosedOn($date->toDateString()),
            'earliestDate' => $this->earliestManageableDate()->toDateString(),
            // The services actually offering slots on the day shown, for the bulk
            // capacity control. Scoped to the day so the list cannot offer a
            // service that has nothing to rescale.
            'servicesForDate' => $slots->flatten(1)
                ->pluck('service')
                ->filter()
                ->unique('id')
                ->sortBy('name')
                ->values(),
            // A past day is still shown - reporting needs to see what was served
            // - but nothing on it can be changed, because the capacity of a
            // finished slot is the denominator of the utilisation figures in
            // Reports. Rewriting it would restate history.
            //
            // startOfDay() on both sides, for the reason in slotIsOnAPastDay():
            // today() is midnight, and midnight is in the past by lunchtime.
            'dayIsEditable' => ! $date->startOfDay()->lt(today()->startOfDay()),
            // Today is not a blanket pass: an individual slot that has already
            // finished today is frozen too. Same predicate the controller
            // enforces, so the disabled rows cannot drift from the refusal.
            'slotHasEnded' => fn (TimeSlot $slot) => $this->slotIsReadOnly($slot),
            // Warn where a slot's capacity outruns what the window's staffing can
            // actually serve, so an administrator sees the problem on the day it
            // bites rather than from a client's wait estimate.
            'capacityWarning' => fn (TimeSlot $slot) => $this->capacity->warning(
                $slot->window,
                $this->slotLengthMinutes($slot),
                (int) ($slot->service?->duration_minutes ?? 0),
                (int) $slot->capacity,
                $slot->service?->name ?? 'this service',
            ),
        ]);
    }

    /**
     * How far back this screen will look.
     *
     * Bounded by the data rather than a fixed number of days: on a fresh install
     * the earliest slot is today, so there is nothing to look back at, and on a
     * long-running one the whole history stays reachable.
     */
    private function earliestManageableDate(): Carbon
    {
        $earliest = TimeSlot::query()->min('slot_date');

        return $earliest
            ? Carbon::parse($earliest)->startOfDay()
            : today();
    }

    /**
     * Has this slot's service time already passed?
     *
     * A slot is editable while it is still running - an administrator should be
     * able to put a second clerk on a counter mid-morning - and frozen once it
     * is over.
     */
    private function slotHasEnded(TimeSlot $slot): bool
    {
        return Carbon::parse($slot->end_at)->isPast();
    }

    /**
     * Is this slot's date already behind us?
     *
     * Compares dates, not timestamps. The `slot_date` column is cast to a date,
     * which materialises as midnight - and midnight today is already in the past
     * by lunchtime. Calling isPast() on it therefore freezes every slot on the
     * current day from midnight onwards, which is the opposite of what the
     * running-slot rule is for.
     */
    private function slotIsOnAPastDay(TimeSlot $slot): bool
    {
        return $slot->slot_date->startOfDay()->lt(today()->startOfDay());
    }

    /**
     * May this slot be changed at all?
     *
     * Two independent reasons, not one. A slot whose time has passed is the
     * record of what was served, and a slot on a past *date* is the record of a
     * whole day - so both are frozen, even a past-day slot that happens to carry
     * a future end time.
     *
     * Kept separate from the individual conditions so the controller and the view
     * cannot disagree about which slots are editable. A rule that only exists in
     * the view is not a rule.
     */
    private function slotIsReadOnly(TimeSlot $slot): bool
    {
        return $this->slotHasEnded($slot) || $this->slotIsOnAPastDay($slot);
    }

    /** Why a slot is frozen, phrased for the administrator reading it. */
    private function readOnlyReason(TimeSlot $slot): string
    {
        return $this->slotIsOnAPastDay($slot)
            ? 'That slot is from a day already past, so it can no longer be changed.'
            : 'That slot has already finished, so it can no longer be changed.';
    }

    /** Minutes between a slot's start and end. */
    private function slotLengthMinutes(TimeSlot $slot): int
    {
        return max(
            1,
            (int) round(
                Carbon::parse($slot->start_at)
                    ->diffInMinutes(Carbon::parse($slot->end_at), false)
            ),
        );
    }

    public function update(Request $request, TimeSlot $slot): RedirectResponse
    {
        /*
         * Two different actions share one endpoint, and they are told apart by an
         * explicit `intent` rather than by which fields happen to be present.
         *
         * Inferring it was the bug this replaces. An unchecked checkbox is not
         * submitted at all, so "is_open absent" cannot mean closed - it only
         * means unticked. Reading the absence as "leave the state alone" made it
         * impossible to close a slot, while opening one worked; and reading it
         * as false (the original behaviour) made saving a capacity silently
         * close the slot instead. Neither guess is safe, so the forms now say
         * what they are.
         */
        $intent = $request->string('intent')->toString();

        if (! in_array($intent, ['capacity', 'open_state'], true)) {
            return back()->with('error', 'That form was not recognised, so nothing was changed.');
        }

        if ($intent === 'open_state') {
            $capacity = (int) $slot->capacity;
            $isOpen = $request->boolean('is_open');
        } else {
            $data = $request->validate([
                'capacity' => ['required', 'integer', 'min:0', 'max:500'],
            ]);

            $capacity = (int) $data['capacity'];
            // The capacity form has no opinion about the open state, so it must
            // never be the thing that changes it.
            $isOpen = (bool) $slot->is_open;
        }

        if ($this->slotIsReadOnly($slot)) {
            return back()
                ->with('error', $this->readOnlyReason($slot))
                ->with('hint', 'Past slots are kept as they were served - they are what the '
                    .'utilisation figures in Reports are calculated from.');
        }

        try {
            $this->admin->updateSlot($slot, $capacity, $isOpen);
        } catch (AdminActionBlockedException $e) {
            return back()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        if ($intent === 'open_state') {
            return back()->with('success', $isOpen
                ? 'Slot is open for booking again.'
                : 'Slot closed. Clients can no longer book it.');
        }

        return back()->with('success', 'Capacity updated. The slot is still '.($isOpen ? 'open' : 'closed').'.');
    }

    public function destroy(TimeSlot $slot): RedirectResponse
    {
        if ($this->slotIsReadOnly($slot)) {
            return back()
                ->with('error', $this->readOnlyReason($slot).' It cannot be removed.')
                ->with('hint', 'Past slots are the record of what was served that day. Removing one '
                    .'would leave the day\'s figures incomplete.');
        }

        try {
            $this->admin->deleteSlot($slot);
        } catch (AdminActionBlockedException $e) {
            return back()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        return back()->with('success', 'Slot removed.');
    }

    /**
     * Bulk-set the capacity of every slot for one service on one day.
     *
     * Used when a window's staffing changes and a whole day needs re-scaling.
     */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'date' => ['required', 'date'],
            'capacity' => ['required', 'integer', 'min:0', 'max:500'],
        ]);

        $service = Service::findOrFail($data['service_id']);
        $capacity = (int) $data['capacity'];

        // Past days are refused wholesale rather than slot by slot. Enforcing it
        // in the loop would produce "N already finished" for a day where every
        // slot is finished, which is a confusing way of saying "pick another day".
        //
        // startOfDay() on both sides: the request carries a date with no time, so
        // parsing it alone yields midnight, which is already past by lunchtime
        // and would freeze the office's own working day.
        if (Carbon::parse($data['date'])->startOfDay()->lt(today()->startOfDay())) {
            return back()
                ->with('error', 'That day is in the past, so nothing was changed.')
                ->with('hint', 'Past slots are the record of what was served. To adjust a future day, '
                    .'choose that date above.');
        }

        $slots = TimeSlot::where('service_id', $service->id)
            ->whereDate('slot_date', $data['date'])
            ->get();

        $updated = 0;
        $skipped = [];

        foreach ($slots as $slot) {
            // Same rule as the row-by-row edit: a slot that has already finished
            // is part of the record of what was served, not a dial to turn. A
            // bulk action must not be able to do what the single form refuses.
            if ($this->slotIsReadOnly($slot)) {
                $skipped[] = $slot->formatTime($slot->start_time).' (already finished)';

                continue;
            }

            try {
                $this->admin->updateSlot($slot, $capacity, $slot->is_open);
                $updated++;
            } catch (AdminActionBlockedException $e) {
                $skipped[] = $slot->formatTime($slot->start_time).' ('.(int) $slot->booked_count.' booked)';
            }
        }

        /*
         * The reasons are grouped rather than concatenated.
         *
         * "Left unchanged: 09:00 (already finished), 09:30 (already finished),
         * 10:00 (3 booked)" is a wall of text that forces the administrator to
         * read every time to work out whether anything they wanted to change was
         * refused. Saying how many of each kind puts the reason first.
         */
        $finished = count(array_filter($skipped, fn (string $line) => str_contains($line, 'already finished')));
        $booked = count($skipped) - $finished;

        if ($skipped === []) {
            return back()->with('success', "Set {$updated} slot(s) for {$service->name} to a capacity of {$capacity}.");
        }

        $reasons = [];

        if ($finished > 0) {
            $reasons[] = $finished.' already finished';
        }

        if ($booked > 0) {
            $reasons[] = $booked.' with clients already booked';
        }

        return back()
            ->with('success', $updated > 0
                ? "Set {$updated} slot(s) to a capacity of {$capacity}."
                : "Nothing was changed for {$service->name} on that day.")
            ->with('error', 'Left unchanged ('.implode(', ', $reasons).'): '
                .implode(', ', $skipped).'.');
    }
}
