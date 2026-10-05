<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AdminActionBlockedException;
use App\Http\Controllers\Controller;
use App\Models\ServiceWindow;
use App\Services\ScheduleAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Service windows: the counters clients are directed to.
 *
 * A window that still holds services or staff is never deletable - see
 * ScheduleAdminService for why that is a rule and not a convenience.
 */
class ServiceWindowController extends Controller
{
    public function __construct(private ScheduleAdminService $admin) {}

    public function index(): View
    {
        return view('admin.windows.index', [
            'windows' => ServiceWindow::withCount(['services', 'staff'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.windows.form', ['window' => new ServiceWindow]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $this->admin->createWindow($data);

        return redirect()->route('admin.windows.index')
            ->with('success', "Service window \"{$data['name']}\" created.");
    }

    public function edit(ServiceWindow $window): View
    {
        return view('admin.windows.form', ['window' => $window]);
    }

    public function update(Request $request, ServiceWindow $window): RedirectResponse
    {
        $this->admin->updateWindow($window, $this->validated($request));

        return redirect()->route('admin.windows.index')
            ->with('success', "Service window \"{$window->name}\" updated.");
    }

    public function destroy(ServiceWindow $window): RedirectResponse
    {
        $name = $window->name;

        try {
            $this->admin->deleteWindow($window);
        } catch (AdminActionBlockedException $e) {
            return back()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        return redirect()->route('admin.windows.index')
            ->with('success', "Service window \"{$name}\" deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        // service_windows.code is UNIQUE, so without this rule a duplicate would
        // surface as an integrity-constraint 500 rather than a message. The
        // window being edited is excluded so saving an unchanged code is fine.
        $current = $request->route('window');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:24', 'regex:/^[A-Z0-9_]+$/',
                'unique:service_windows,code'.($current ? ','.$current->id : ''),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'avg_service_minutes' => ['required', 'integer', 'min:1', 'max:240'],
        ], [
            // The code is the public route key, so a lowercase or spaced code
            // would produce an unreachable URL.
            'code.regex' => 'The code may contain only capital letters, numbers, and underscores.',
            'code.unique' => 'Another window already uses that code.',
        ]);

        // An unchecked checkbox is simply absent from the payload, so this has to
        // be read from the request rather than from the validated array.
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
