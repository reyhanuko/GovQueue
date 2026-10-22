<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBookingDay;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Services\SlotAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public service catalogue. Reading the list does not require an account, but
 * booking does - see the booking screen in Sprint 3.
 */
class ServiceCatalogController extends Controller
{
    /* The service page's availability preview shows one day at a time, like booking. */
    use ResolvesBookingDay;

    public function __construct(private SlotAvailabilityService $availability) {}

    public function index(Request $request): View
    {
        $services = Service::query()
            ->active()
            ->with('window')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            ->when($request->filled('window'), fn ($q) => $q
                ->where('service_window_id', $request->integer('window')))
            ->orderBy('name')
            ->get();

        // Annotate each service with what a client could actually book right
        // now, so the catalogue never advertises a closed service.
        //
        // Batched into two queries rather than asked for service by service: this
        // is the busiest public page, and the per-service form cost two queries
        // each - measured at 16 on a catalogue of 8, growing with the services the
        // office offers.
        $availability = $this->availability->catalogueAvailabilityFor($services);

        $services->each(function (Service $service) use ($availability) {
            $service->open_slots = (int) ($availability['counts']->get($service->id) ?? 0);
            $service->next_slot = $availability['next']->get($service->id);

            // Slots that exist at all, so the card can tell "full" from
            // "nothing published". Zero for both is the whole problem.
            $service->scheduled_slots = (int) ($availability['scheduled']->get($service->id) ?? 0);
        });

        return view('services.index', [
            'services' => $services,
            'windows' => ServiceWindow::where('is_active', true)->orderBy('name')->get(),
            'filters' => $request->only(['q', 'window']),
        ]);
    }

    public function show(Request $request, Service $service): View
    {
        abort_unless($service->is_active, 404);

        $service->load('window');

        $availability = $this->availability->availabilityFor($service);

        return view('services.show', [
            'service' => $service,
            'availability' => $availability,
            // The availability preview shows one day at a time, same as booking.
            // It used to list every slot across the whole horizon, so the
            // section was a wall that pushed "Book this service" far below the
            // fold - the same problem reported on the booking screen.
            'selectedDate' => $this->resolveBookingDay($request, $availability),
            'dayLabel' => fn (string $date) => $this->availability->dayLabel($date),
            'dayUrl' => fn (string $date) => route('services.show', [$service, 'date' => $date]),
            // The preview is not a booking form, so nothing is pre-reserved for
            // whoever is looking at it.
            'bookedSlotIds' => [],
            'closedDates' => $this->availability->closedDates(),
            'nextSlot' => $this->availability->nextAvailableSlot($service),
        ]);
    }
}
