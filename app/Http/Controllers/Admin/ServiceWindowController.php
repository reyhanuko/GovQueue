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
            /*
             * Same reason as the services screen: the Delete button used to
             * offer a confirmation promising that the window "and its services
             * will be removed", which is precisely what the server refuses to do
             * when any service is still assigned to it.
             */
            'deletionBlockers' => ServiceWindow::orderBy('name')->get()
                ->mapWithKeys(fn (ServiceWindow $w) => [$w->id => $this->admin->deletionBlockerForWindow($w)])
                ->all(),
        ]);
    }

    public function create(): View
    {
        return view('admin.windows.form', [
            'window' => new ServiceWindow,
            // Nothing depends on a code that does not exist yet.
            'codeIsLocked' => false,
        ]);
    }

    /**
     * Whether this window's code may still be changed.
     *
     * Unsaved windows have no code worth protecting. Once a window has anything
     * attached, its code is part of the public surface.
     */
    private function codeIsLocked(ServiceWindow $window): bool
    {
        if (! $window->exists) {
            return false;
        }

        return $window->services()->exists()
            || $window->staff()->exists()
            || $window->timeSlots()->exists();
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
        return view('admin.windows.form', [
            'window' => $window,
            /*
             * A window code is an identifier, not a label.
             *
             * It appears in public URLs and on the lobby display, and it is the
             * prefix queue numbers are issued with, so renaming it strands links
             * a client was already given. Once anything depends on the window the
             * code is locked rather than merely warned about: the service form has
             * always warned, and a warning nobody acted on is not a control.
             */
            'codeIsLocked' => $this->codeIsLocked($window),
        ]);
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
