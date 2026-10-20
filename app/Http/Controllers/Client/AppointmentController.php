<?php

namespace App\Http\Controllers\Client;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Concerns\ResolvesBookingDay;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\BookingService;
use App\Services\SlotAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    /*
     * Filters offered on the history list.
     *
     * Declared here rather than derived from the data so the row is stable:
     * a filter row whose links come and go with whatever happened to be in the
     * database is a filter row nobody can rely on.
     */
    private const HISTORY_FILTERS = ['completed', 'cancelled', 'no_show'];

    /** How many past appointments one page shows. */
    private const HISTORY_PER_PAGE = 8;

    /*
     * Moving an appointment uses the same slot picker as booking a new one, so
     * it has to agree with it about which day to open on.
     */
    use ResolvesBookingDay;

    public function index(Request $request): View
    {
        /*
         * Two queries rather than one, because the two halves want opposite
         * things.
         *
         * Upcoming is small and wants the soonest first. History wants the most
         * recent first, and that is the opposite sort - so sharing one
         * "scheduled_at IS NULL, scheduled_at ASC" order meant history ran oldest
         * to newest, and a booking cancelled today for a date next month landed
         * at the very bottom, furthest from the thing that just happened.
         *
         * History is also paginated now. It was one unbounded get() of every
         * appointment this client has ever had, rendered as one table, which for
         * a long-standing resident is a page nobody scrolls to the end of.
         */
        $upcoming = Appointment::query()
            ->with(['service', 'timeSlot', 'window'])
            ->where('user_id', $request->user()->id)
            ->whereIn('status', $this->activeStatuses())
            ->orderByRaw('scheduled_at IS NULL, scheduled_at ASC')
            ->get()
            ->filter(fn (Appointment $a) => $a->isStillRelevant())
            ->values();

        $filter = (string) $request->query('status', '');

        if (! in_array($filter, self::HISTORY_FILTERS, true)) {
            $filter = '';
        }

        $history = Appointment::query()
            ->with(['service', 'timeSlot', 'window'])
            ->where('user_id', $request->user()->id)
            ->whereIn('status', $this->closedStatuses())
            ->when($filter !== '', fn ($q) => $q->where('status', $filter))
            ->orderByDesc('scheduled_at')
            ->orderByDesc('created_at')
            ->paginate(self::HISTORY_PER_PAGE)
            ->withQueryString();

        return view('client.appointments.index', [
            'upcoming' => $upcoming,
            'history' => $history,
            'statusFilter' => $filter,
            'historyFilters' => self::HISTORY_FILTERS,
        ]);
    }

    /** @return array<int, string> */
    private function activeStatuses(): array
    {
        return collect(AppointmentStatus::cases())
            ->filter(fn (AppointmentStatus $s) => $s->isActive())
            ->map(fn (AppointmentStatus $s) => $s->value)
            ->all();
    }

    /** @return array<int, string> */
    private function closedStatuses(): array
    {
        return collect(AppointmentStatus::cases())
            ->filter(fn (AppointmentStatus $s) => $s->isClosed())
            ->map(fn (AppointmentStatus $s) => $s->value)
            ->all();
    }

    public function show(Request $request, Appointment $appointment): View
    {
        abort_unless((int) $appointment->user_id === (int) $request->user()->id, 403);

        // The transaction too, so the page can say what was actually paid and
        // give the receipt reference. A client who paid a fee is entitled to
        // see the amount and the receipt number, and neither was on the page.
        $appointment->load(['service.window', 'timeSlot', 'window', 'statusHistories', 'transaction']);

        // Only a ticket that is actually in play gets a live board; a cancelled
        // or completed appointment has nothing to update.
        $showLiveStatus = $appointment->status->isActive();

        return view('client.appointments.show', [
            'appointment' => $appointment,
            'showLiveStatus' => $showLiveStatus,
        ]);
    }

    /**
     * Pick a new slot for an existing appointment.
     */
    public function rescheduleForm(Request $request, Appointment $appointment): View|RedirectResponse
    {
        abort_unless((int) $appointment->user_id === (int) $request->user()->id, 403);

        if (! in_array($appointment->status->value, ['pending', 'confirmed'], true)) {
            return redirect()->route('client.appointments.show', $appointment)
                ->with('error', 'Only an upcoming, unstarted appointment can be moved.');
        }

        $service = $appointment->service;
        $availability = app(SlotAvailabilityService::class);

        if ($availability->availabilityFor($service)->isEmpty()) {
            app(BookingService::class)->ensureSlotsExist($service);
        }

        return view('client.booking.reschedule', [
            'appointment' => $appointment,
            'service' => $service,
            'availability' => $availability->availabilityFor($service),
            'selectedDate' => $this->resolveBookingDay($request, $availability->availabilityFor($service)),
            'dayLabel' => fn (string $date) => $availability->dayLabel($date),
            'dayUrl' => fn (string $date) => route('client.appointments.reschedule', [$appointment, 'date' => $date]),
            'bookedSlotIds' => collect($availability->availabilityFor($service)->keys())
                ->mapWithKeys(fn ($date) => [$date => $availability->bookedSlotIdsFor($request->user()->id, $date)])
                ->all(),
        ]);
    }
}
