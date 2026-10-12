<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\AuditLog;
use App\Services\PhilippineHolidayCalendar;
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
    public function __construct(
        private ScheduleAdminService $admin,
        private PhilippineHolidayCalendar $calendar,
    ) {}

    public function index(Request $request): View
    {
        $holidays = Holiday::query()
            ->orderBy('date')
            ->get();

        // Only bother counting affected clients for dates that are still ahead.
        $affected = collect();

        foreach ($holidays as $holiday) {
            /*
             * startOfDay() on both sides, because `date` is cast to a date and
             * therefore materialises as midnight - and midnight today is already
             * in the past by lunchtime. Without this, declaring today a closure
             * silently reported that nobody was affected, on the one screen that
             * exists to answer "which clients need rebooking?".
             *
             * The same trap is documented and fixed in TimeSlotController.
             */
            if ($holiday->date->startOfDay()->lt(today()->startOfDay())) {
                continue;
            }

            $affected[$holiday->id] = $this->admin->clientsAffectedByHoliday($holiday->date->toDateString());
        }

        return view('admin.holidays.index', [
            'holidays' => $holidays,
            'affected' => $affected,
            'seedYear' => (int) $request->integer('seed_year', now()->year),
        ]);
    }

    /**
     * Seed the regular national holidays for a year.
     *
     * Never overwrites: a date that is already declared is left exactly as it
     * is. An administrator may have renamed a closure or added a note that
     * matters locally (a half-day, a local city charter day on the same date),
     * and silently replacing that would lose the local decision.
     */
    public function seedRegularHolidays(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
        ]);

        $year = (int) $data['year'];
        $candidates = $this->calendar->regularHolidays($year);

        $existing = Holiday::whereIn('date', array_keys($candidates))
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        $added = 0;
        $skipped = 0;

        foreach ($candidates as $date => $name) {
            if (in_array($date, $existing, true)) {
                $skipped++;

                continue;
            }

            $this->admin->createHoliday([
                'date' => $date,
                'name' => $name,
                'notes' => 'Seeded as a Philippine regular holiday. Renaming or removing this is fine - '
                    .'seeded closures never overwrite an existing one.',
            ]);

            $added++;
        }

        AuditLog::record('holiday.seeded', null, [
            'year' => $year,
            'added' => $added,
            'skipped_existing' => $skipped,
        ]);

        $message = sprintf('Seeded %d regular holiday(s) for %d.', $added, $year);

        if ($skipped > 0) {
            $message .= sprintf(
                ' %d date(s) were already declared and were left untouched.',
                $skipped,
            );
        }

        if ($added === 0) {
            return back()->with('hint', $message.' Nothing changed.');
        }

        return back()->with('success', $message);
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
