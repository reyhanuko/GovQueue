<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Services\ScheduleAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Declared office closures.
 *
 * Adding a holiday hides every slot on that date from the booking screens. It
 * does not cancel clients who already booked, so the screen lists exactly who
 * is affected - that decision belongs to a person, not to a date field.
 */
class HolidayController extends Controller
{
    public function __construct(private ScheduleAdminService $admin) {}

    public function index(Request $request): View
    {
        $holidays = Holiday::query()
            ->orderBy('date')
            ->get();

        // Only bother counting affected clients for dates that are still ahead.
        $affected = collect();

        foreach ($holidays as $holiday) {
            if ($holiday->date->isPast()) {
                continue;
            }

            $affected[$holiday->id] = $this->admin->clientsAffectedByHoliday($holiday->date->toDateString());
        }

        return view('admin.holidays.index', [
            'holidays' => $holidays,
            'affected' => $affected,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // The unique rule matters: holidays.date is UNIQUE, so a duplicate would
        // otherwise surface as a 500 from the constraint rather than a message.
        $data = $request->validate([
            'date' => ['required', 'date', 'unique:holidays,date'],
            'name' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'date.unique' => 'That date is already declared a holiday.',
        ]);

        $this->admin->createHoliday($data);

        $booked = $this->admin->clientsAffectedByHoliday($data['date'])->count();

        return back()->with('success', $booked > 0
            ? sprintf(
                'Holiday saved. %d client(s) already hold a booking on that date and still need to be '
                    .'rebooked or contacted - their appointments were not cancelled.',
                $booked,
            )
            : 'Holiday saved. Clients can no longer book that date.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $date = $holiday->date->toDateString();

        $this->admin->deleteHoliday($holiday);

        return back()->with('success', "Holiday on {$date} removed. Clients can book that date again.");
    }
}
