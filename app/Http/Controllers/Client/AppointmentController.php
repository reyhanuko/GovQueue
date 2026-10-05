<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\BookingService;
use App\Services\SlotAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function index(Request $request): View
    {
        $appointments = Appointment::query()
            ->with(['service', 'timeSlot', 'window'])
            ->where('user_id', $request->user()->id)
            ->orderByRaw('scheduled_at IS NULL, scheduled_at ASC')
            ->orderByDesc('created_at')
            ->get();

        return view('client.appointments.index', [
            'appointments' => $appointments,
            'upcoming' => $appointments->filter(fn ($a) => $a->status->isActive())->values(),
            'past' => $appointments->filter(fn ($a) => $a->status->isClosed())->values(),
        ]);
    }

    public function show(Request $request, Appointment $appointment): View
    {
        abort_unless((int) $appointment->user_id === (int) $request->user()->id, 403);

        $appointment->load(['service.window', 'timeSlot', 'window', 'statusHistories']);

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
            'dayLabel' => fn (string $date) => $availability->dayLabel($date),
            'bookedSlotIds' => collect($availability->availabilityFor($service)->keys())
                ->mapWithKeys(fn ($date) => [$date => $availability->bookedSlotIdsFor($request->user()->id, $date)])
                ->all(),
        ]);
    }
}
