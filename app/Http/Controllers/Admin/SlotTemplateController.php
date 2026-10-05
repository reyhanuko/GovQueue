<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AdminActionBlockedException;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\SlotTemplate;
use App\Services\ScheduleAdminService;
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
    public function __construct(private ScheduleAdminService $admin) {}

    public function index(Service $service): View
    {
        $service->load('window');

        return view('admin.templates.index', [
            'service' => $service,
            'templates' => $service->slotTemplates()->orderBy('day_of_week')->orderBy('start_time')->get(),
        ]);
    }

    public function store(Request $request, Service $service): RedirectResponse
    {
        $data = $this->validated($request);
        $data['service_id'] = $service->id;

        try {
            $this->admin->createTemplate($data);
        } catch (AdminActionBlockedException $e) {
            return back()->withInput()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        return back()->with('success', 'Template added. Regenerate slots to apply it to concrete dates.');
    }

    public function update(Request $request, SlotTemplate $template): RedirectResponse
    {
        try {
            $this->admin->updateTemplate($template, $this->validated($request));
        } catch (AdminActionBlockedException $e) {
            return back()->withInput()->with('error', $e->getMessage())->with('hint', $e->hint);
        }

        return back()->with('success', 'Template updated. Regenerate slots to apply the change.');
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
        $data = $request->validate([
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
        ], [
            'start_time.date_format' => 'Use a 24-hour time such as 08:30.',
            'end_time.date_format' => 'Use a 24-hour time such as 12:00.',
            'capacity.min' => 'A slot must have at least one place, or no client could ever book it.',
        ]);

        $data['day_of_week'] = (int) $data['day_of_week'];
        $data['slot_minutes'] = (int) $data['slot_minutes'];
        $data['capacity'] = (int) $data['capacity'];
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
