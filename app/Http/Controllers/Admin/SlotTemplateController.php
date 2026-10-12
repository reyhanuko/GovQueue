<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AdminActionBlockedException;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\SlotTemplate;
use App\Services\ScheduleAdminService;
use App\Services\StaffingCapacityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Recurring weekly slot templates for one service.
 *
 * These are the rule; the concrete time_slots rows are the expansion of it.
 * Nothing here can produce a template that yields no slots - see
 * ScheduleAdminService::assertTemplateTimesAreUsable().
 */
class SlotTemplateController extends Controller
{
    public function __construct(
        private ScheduleAdminService $admin,
        private StaffingCapacityService $capacity,
    ) {}

    public function index(Service $service): View
    {
        $service->load('window');

        return view('admin.templates.index', [
            'service' => $service,
            'templates' => $service->slotTemplates()->orderBy('day_of_week')->orderBy('start_time')->get(),
            // Staffing warnings are computed here rather than in the view so the
            // template form and the daily slots screen can share one wording.
            'capacityWarning' => fn (SlotTemplate $template) => $this->capacity->warning(
                $service->window,
                (int) $template->slot_minutes,
                (int) $service->duration_minutes,
                (int) $template->capacity,
                $service->name,
            ),
        ]);
    }

    public function store(Request $request, Service $service): RedirectResponse
    {
        $data = $this->validated($request);
        $data['service_id'] = $service->id;

        try {
            $template = $this->admin->createTemplate($data);
        } catch (AdminActionBlockedException $e) {
            return back()->withInput()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        // A warning, never a block: the template is saved either way. Existing
        // configurations must keep working, and only the administrator can
        // decide whether the extra places are justified by staff they have not
        // yet assigned to this window.
        $warning = $service->load('window') && $this->capacity->warning(
            $service->window,
            (int) $data['slot_minutes'],
            (int) $service->duration_minutes,
            (int) $data['capacity'],
            $service->name,
        );

        return back()
            ->with('success', 'Template added. Regenerate slots to apply it to concrete dates.')
            ->with('warning', $warning);
    }

    public function update(Request $request, SlotTemplate $template): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $template = $this->admin->updateTemplate($template, $data);
        } catch (AdminActionBlockedException $e) {
            return back()->withInput()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        $service = $template->service;
        $warning = $service->load('window') && $this->capacity->warning(
            $service->window,
            (int) $data['slot_minutes'],
            (int) $service->duration_minutes,
            (int) $data['capacity'],
            $service->name,
        );

        return back()
            ->with('success', 'Template updated. Regenerate slots to apply the change.')
            ->with('warning', $warning);
    }

    public function destroy(SlotTemplate $template): RedirectResponse
    {
        try {
            $this->admin->deleteTemplate($template);
        } catch (AdminActionBlockedException $e) {
            return back()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        return back()->with('success', 'Template removed. Existing slots already generated are left alone.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        /*
         * Both HH:MM and HH:MM:SS are accepted.
         *
         * The time input submits HH:MM, but this endpoint is also reachable
         * with a seconds-precision value, and rejecting that produced a
         * validation error naming a field the administrator had never edited
         * and could not fix from the message.
         */
        $data = $request->validate([
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s'],
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
        ], [
            // Naming the field and the fix. "Use a 24-hour time" on its own left
            // an administrator hunting for a setting that did not exist.
            'start_time.date_format' => 'Opening time is not valid. Use 24-hour time, for example 08:30 for opening and 13:00 for opening at 1pm.',
            'end_time.date_format' => 'Closing time is not valid. Use 24-hour time, for example 12:00 for closing at midday and 17:00 for closing at 5pm.',
            'start_time.required' => 'Enter the time this window opens.',
            'end_time.required' => 'Enter the time this window closes.',
            'capacity.min' => 'A slot must have at least one place, or no client could ever book it.',
        ]);

        // Stored as HH:MM regardless of what arrived, so the column is not
        // carrying two different shapes of the same value.
        $data['start_time'] = substr((string) $data['start_time'], 0, 5);
        $data['end_time'] = substr((string) $data['end_time'], 0, 5);

        $data['day_of_week'] = (int) $data['day_of_week'];
        $data['slot_minutes'] = (int) $data['slot_minutes'];
        $data['capacity'] = (int) $data['capacity'];
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
