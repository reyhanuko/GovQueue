<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AdminActionBlockedException;
use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Services\ScheduleAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function __construct(private ScheduleAdminService $admin) {}

    public function index(Request $request): View
    {
        $date = $request->date('date') ?? today();

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
        ]);
    }

    public function update(Request $request, TimeSlot $slot): RedirectResponse
    {
        $data = $request->validate([
            'capacity' => ['required', 'integer', 'min:0', 'max:500'],
        ]);

        try {
            $this->admin->updateSlot(
                $slot,
                (int) $data['capacity'],
                $request->boolean('is_open'),
            );
        } catch (AdminActionBlockedException $e) {
            return back()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        $message = $request->boolean('is_open')
            ? 'Slot updated and still open for booking.'
            : 'Slot updated and closed. Clients can no longer book it.';

        return back()->with('success', $message);
    }

    public function destroy(TimeSlot $slot): RedirectResponse
    {
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

        $slots = TimeSlot::where('service_id', $service->id)
            ->whereDate('slot_date', $data['date'])
            ->get();

        $updated = 0;
        $skipped = [];

        foreach ($slots as $slot) {
            try {
                $this->admin->updateSlot($slot, $capacity, $slot->is_open);
                $updated++;
            } catch (AdminActionBlockedException $e) {
                $skipped[] = $slot->formatTime($slot->start_time).' ('.(int) $slot->booked_count.' booked)';
            }
        }

        if ($skipped === []) {
            return back()->with('success', "Set {$updated} slot(s) for {$service->name} to a capacity of {$capacity}.");
        }

        return back()
            ->with('success', "Set {$updated} slot(s) to a capacity of {$capacity}.")
            ->with('error', 'Left unchanged because clients are already booked: '.implode(', ', $skipped).'.');
    }
}
