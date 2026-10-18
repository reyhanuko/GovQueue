<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Services\OpsNotificationService;
use App\Services\PhilippineHolidayCalendar;
use App\Services\ScheduleAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        private OpsNotificationService $ops,
    ) {}

    public function index(Request $request): View
    {
        /*
         * startOfDay() on both sides of every past/today comparison in this file.
         * `date` is cast to a date and therefore materialises as midnight, and
         * midnight today is already in the past by lunchtime. Comparing with
         * plain isPast() or lt(today()) badges *today* as past and skips the
         * affected-clients query for it - on the one screen that exists to
         * answer "which clients need rebooking?".
         *
         * The same trap is documented and fixed in TimeSlotController.
         */
        $today = today()->startOfDay();

        $upcoming = Holiday::query()
            ->whereDate('date', '>=', $today->toDateString())
            ->orderBy('date')
            ->get();

        /*
         * Past closures are kept out of the default view. They cannot affect a
         * booking - the date is already unbookable for being past - so on a
         * screen whose whole job is deciding who needs contacting, they are
         * noise stacked above the rows that need a decision. They stay in the
         * database and behind ?show=past, because the record of why the office
         * was shut last March is still worth having.
         */
        $past = Holiday::query()
            ->whereDate('date', '<', $today->toDateString())
            ->orderByDesc('date')
            ->get();

        // Only bother counting affected clients for dates that are still ahead.
        $affected = collect();

        foreach ($upcoming as $holiday) {
            $affected[$holiday->id] = $this->admin->clientsAffectedByHoliday($holiday->date->toDateString());
        }

        return view('admin.holidays.index', [
            'upcoming' => $upcoming,
            'past' => $past,
            'affected' => $affected,
            'showPast' => $request->boolean('show_past'),
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
        /*
         * min is the current year rather than a fixed 2020. Seeding a year that
         * has already gone is the route by which dozens of permanently useless
         * rows got in: every one of them a date the office was shut, recorded
         * correctly but unable to hide a slot or to have an affected client.
         *
         * Dates earlier than today *within* the current year are still declared,
         * because they are the real record of a day the office was shut and the
         * seeder is an authoritative import rather than a forward-looking
         * declaration. They simply sort into the past section.
         */
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:'.now()->year, 'max:2100'],
        ], [
            'year.min' => 'Choose this year or a later year. Holidays in a year that has already '
                .'passed cannot be booked and are kept in the past section.',
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
        /*
         * after_or_equal:today closes the door the "Add national holidays" form
         * used to leave open. Seeding a year that has already gone left a dozen
         * rows that could never hide a slot and never had an affected client,
         * and because the list was ordered ascending they sat at the top of the
         * screen pushing the actionable dates out of view.
         *
         * Rows that already exist are left alone - this only refuses new ones.
         */
        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today', 'unique:holidays,date'],
            'name' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'date.unique' => 'That date is already declared a holiday.',
            'date.after_or_equal' => 'A closure cannot be declared for a date that has already passed.',
        ]);

        $this->admin->createHoliday($data);

        $booked = $this->admin->clientsAffectedByHoliday($data['date'])->count();

        /*
         * Push it, rather than only writing it on the screen the administrator
         * is already looking at. The flash below and the amber panel on this
         * page both report the count, but neither reaches an administrator who
         * is elsewhere - and this is the one case on the holidays screen where
         * a client is waiting on a decision.
         */
        $this->ops->closureClashesWithBookings(
            Carbon::parse($data['date'])->format('j F Y'),
            $booked,
        );

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

        /*
         * A past closure cannot be removed.
         *
         * It looks inert, because booking only ever reads closures from today
         * onward (SlotAvailabilityService::queryClosedDates) - but that is not
         * the only consumer. SlotGeneratorService::generate() takes every
         * holiday row with no date filter at all and skips those dates, and
         * DemoActivitySeeder generates slots 28 days into the past. So removing
         * a past closure makes a re-run emit slots for a day the office was
         * recorded as shut. TimeSlotController::isHoliday has the same shape: it
         * fires for any date, so the admin slots screen would stop showing that
         * day as closed.
         *
         * The row is also the only remaining record that the office was closed
         * on that date, which is why it is refused rather than archived.
         *
         * Checked here and not in the view: hiding the button is a courtesy,
         * this is the rule.
         */
        if ($holiday->date->startOfDay()->lt(today()->startOfDay())) {
            return back()->with('error', sprintf(
                'The closure on %s cannot be removed. It is in the past, so it is the record that the '
                    .'office was closed that day, and removing it would let slots be generated for a '
                    .'date the office was shut.',
                $holiday->date->format('j F Y'),
            ));
        }

        $this->admin->deleteHoliday($holiday);

        return back()->with('success', "Holiday on {$date} removed. Clients can book that date again.");
    }
}
