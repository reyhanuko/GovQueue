<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\User;
use App\Services\QueueBroadcaster;
use App\Services\ScheduleAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The administrator's landing screen.
 *
 * Answers the questions an administrator opens the back office to ask: is the
 * office open today, what is the queue doing, is anything misconfigured, and did
 * that change I made five minutes ago actually take effect.
 */
class AdminDashboardController extends Controller
{
    public function __construct(
        private ScheduleAdminService $schedule,
        private QueueBroadcaster $broadcaster,
    ) {}

    public function index(Request $request): View
    {
        $today = $request->date('date') ?? today();
        $horizon = (int) config('govqueue.booking_horizon_days');

        $todayAppointments = Appointment::query()
            ->whereDate('queue_date', $today)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $upcoming = Appointment::query()
            ->whereDate('scheduled_at', '>=', $today)
            ->whereIn('status', ['pending', 'confirmed'])
            ->count();

        // Clients holding a booking on a declared holiday: they are not cancelled
        // automatically, but somebody has to decide what happens to them.
        $holidayConflicts = Holiday::query()
            ->whereDate('date', '>=', $today->toDateString())
            ->whereDate('date', '<=', $today->copy()->addDays($horizon)->toDateString())
            ->get()
            ->map(fn (Holiday $holiday) => [
                'holiday' => $holiday,
                'count' => $this->schedule->clientsAffectedByHoliday($holiday->date->toDateString())->count(),
            ])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values();

        return view('admin.dashboard', [
            'today' => $today,
            'statusCounts' => [
                'waiting' => (int) ($todayAppointments[AppointmentStatus::Confirmed->value] ?? 0)
                    + (int) ($todayAppointments[AppointmentStatus::CheckedIn->value] ?? 0),
                'serving' => (int) ($todayAppointments[AppointmentStatus::Serving->value] ?? 0),
                'completed' => (int) ($todayAppointments[AppointmentStatus::Completed->value] ?? 0),
                'cancelled' => (int) ($todayAppointments[AppointmentStatus::Cancelled->value] ?? 0),
            ],
            'upcoming' => $upcoming,
            'counts' => [
                'windows' => ServiceWindow::where('is_active', true)->count(),
                'services' => Service::active()->count(),
                'staff' => User::whereIn('role', ['staff', 'admin'])->where('is_active', true)->count(),
                'holidays' => Holiday::whereDate('date', '>=', $today->toDateString())->count(),
            ],
            'windows' => ServiceWindow::withCount(['services', 'staff'])
                ->orderBy('name')
                ->get(),
            'missingTemplates' => $this->schedule->servicesMissingTemplates(),
            'holidayConflicts' => $holidayConflicts,
            'realtimeHealthy' => $this->broadcaster->isHealthy(),
            'recentAudit' => AuditLog::with('user')->latest()->limit(12)->get(),
            'horizon' => $horizon,
        ]);
    }

    /**
     * One-click regeneration of concrete slots from the templates.
     */
    public function regenerate(Request $request): RedirectResponse
    {
        $days = $request->integer('days') ?: (int) config('govqueue.booking_horizon_days');

        $result = $this->schedule->regenerateSlots($days);

        return back()->with('success', sprintf(
            'Regenerated slots for the next %d days: %d created, %d already existed.',
            $days,
            $result['created'],
            $result['skipped'],
        ));
    }
}
