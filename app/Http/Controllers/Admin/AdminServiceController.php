<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AdminActionBlockedException;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Services\ScheduleAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Service catalogue administration.
 */
class AdminServiceController extends Controller
{
    public function __construct(private ScheduleAdminService $admin) {}

    public function index(Request $request): View
    {
        $services = Service::query()
            ->with('window')
            ->withCount(['slotTemplates', 'appointments'])
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q')->trim().'%'))
            ->when($request->filled('window'), fn ($q) => $q->where('service_window_id', $request->integer('window')))
            ->when($request->filled('status'), function ($q) use ($request) {
                match ($request->string('status')->toString()) {
                    'inactive' => $q->where('is_active', false),
                    'active' => $q->where('is_active', true),
                    default => null,
                };
            })
            ->orderBy('name')
            ->get();

        return view('admin.services.index', [
            'services' => $services,
            'windows' => ServiceWindow::orderBy('name')->get(),
            'filters' => $request->only(['q', 'window', 'status']),
        ]);
    }

    public function create(): View
    {
        return view('admin.services.form', [
            'service' => new Service,
            'windows' => $this->windows(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $this->admin->createService($data);

        return redirect()->route('admin.services.index')
            ->with('success', "Service \"{$data['name']}\" created. Add a slot template so it has bookable times.");
    }

    public function edit(Service $service): View
    {
        $service->load('window');

        return view('admin.services.form', [
            'service' => $service,
            'windows' => $this->windows(),
        ]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $before = (int) $service->service_window_id;

        $this->admin->updateService($service, $this->validated($request));

        $note = (int) $service->fresh()->service_window_id !== $before
            ? ' Its existing slots and upcoming appointments were moved to the new window.'
            : '';

        return redirect()->route('admin.services.index')
            ->with('success', "Service \"{$service->name}\" updated.".$note);
    }

    public function destroy(Service $service): RedirectResponse
    {
        $name = $service->name;

        try {
            $this->admin->deleteService($service);
        } catch (AdminActionBlockedException $e) {
            return back()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        return redirect()->route('admin.services.index')
            ->with('success', "Service \"{$name}\" deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        // services.code is UNIQUE, so without this a duplicate would surface as
        // an integrity-constraint 500. The service being edited is excluded so an
        // unchanged code saves cleanly.
        $current = $request->route('service');

        $data = $request->validate([
            'service_window_id' => ['required', 'integer', 'exists:service_windows,id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required', 'string', 'max:24', 'regex:/^[A-Z0-9_-]+$/',
                'unique:services,code'.($current ? ','.$current->id : ''),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'requirements' => ['nullable', 'string', 'max:2000'],
            'fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:480'],
        ], [
            'code.regex' => 'The code may contain only capital letters, numbers, dashes, and underscores.',
            'code.unique' => 'Another service already uses that code.',
        ]);

        $data['service_window_id'] = (int) $data['service_window_id'];
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    /** @return Collection<int, ServiceWindow> */
    private function windows()
    {
        // Inactive windows are still offered when editing, so moving a service
        // off a retired counter is possible; a new service just cannot be placed
        // on one.
        return ServiceWindow::orderBy('name')->get();
    }
}
