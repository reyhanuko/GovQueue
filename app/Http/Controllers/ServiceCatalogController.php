<?php

namespace App\Http\Controllers;

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
        });

        return view('services.index', [
            'services' => $services,
            'windows' => ServiceWindow::where('is_active', true)->orderBy('name')->get(),
            'filters' => $request->only(['q', 'window']),
        ]);
    }

    public function show(Service $service): View
    {
        abort_unless($service->is_active, 404);

        $service->load('window');

        $availability = $this->availability->availabilityFor($service);

        return view('services.show', [
            'service' => $service,
            'availability' => $availability,
            'dayLabel' => fn (string $date) => $this->availability->dayLabel($date),
            'closedDates' => $this->availability->closedDates(),
            'nextSlot' => $this->availability->nextAvailableSlot($service),
        ]);
    }
}
