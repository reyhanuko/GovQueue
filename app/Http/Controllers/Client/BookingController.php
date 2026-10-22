<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\AppointmentConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Client\Concerns\ResolvesBookingDay;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\BookingService;
use App\Services\SlotAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The client-facing booking flow: pick a service, pick a slot, confirm.
 *
 * All state-changing actions go through BookingService so the capacity lock is
 * always applied. Nothing here increments booked_count directly.
 */
class BookingController extends Controller
{
    /* The same picker serves new bookings and moved appointments. */
    use ResolvesBookingDay;

    public function __construct(
        private BookingService $booking,
        private SlotAvailabilityService $availability,
    ) {}

    public function create(Request $request, Service $service): View
    {
        abort_unless($service->is_active, 404);
        $service->load('window');

        $user = $request->user();

        // A fresh database may have no generated slots yet.
        if ($this->availability->availabilityFor($service)->isEmpty()) {
            $this->booking->ensureSlotsExist($service);
        }

        $availability = $this->availability->availabilityFor($service);

        /*
         * The service page links a client straight to a specific day with
         * ?date=YYYY-MM-DD. ResolvesBookingDay honours it, and otherwise falls
         * back to the first day with availability, so the picker always opens on
         * exactly one day rather than the whole booking horizon.
         */
        $selectedDate = $this->resolveBookingDay($request, $availability);

        return view('client.booking.create', [
            'service' => $service,
            'availability' => $availability,
            'dayLabel' => fn (string $date) => $this->availability->dayLabel($date),
            'dayUrl' => fn (string $date) => route('client.booking.create', [$service, 'date' => $date]),
            'priorities' => $this->booking->claimablePriorities($user),
            'user' => $user,
            'hasActiveBooking' => $this->availability->hasActiveBooking($user->id, $service),
            'activeBookings' => $this->activeBookingsFor($user, $service),
            'bookedSlotIds' => $this->bookedSlotIds($user, $availability->keys()->all()),
            'selectedDate' => $selectedDate,
        ]);
    }

    public function store(Request $request, Service $service): RedirectResponse
    {
        abort_unless($service->is_active, 404);

        $data = $request->validate([
            'time_slot_id' => ['required', 'integer'],
            'claims_senior_citizen' => ['nullable', 'boolean'],
            'claims_pwd' => ['nullable', 'boolean'],
            'claims_pregnant' => ['nullable', 'boolean'],
            'priority_reference' => ['nullable', 'string', 'max:120'],
            'client_note' => ['nullable', 'string', 'max:500'],
        ]);

        $claims = $data;
        $user = $request->user();

        // A client cannot tick senior priority unless their birthdate qualifies,
        // so the flag is derived rather than trusted from the form.
        $claims['claims_senior_citizen'] = $user->claims_senior_citizen;

        // Unchecked checkboxes are simply absent from the payload, so read them
        // defensively rather than assuming every key is present.
        $claims['claims_pwd'] = (bool) ($claims['claims_pwd'] ?? false);
        $claims['claims_pregnant'] = (bool) ($claims['claims_pregnant'] ?? false);

        $needsReference = $claims['claims_pwd'] || $claims['claims_pregnant'];

        if ($needsReference && blank($claims['priority_reference'] ?? null)) {
            return back()
                ->withInput()
                ->withErrors([
                    'priority_reference' => 'Please enter your supporting reference so staff can verify your priority claim.',
                ]);
        }

        try {
            $appointment = $this->booking->book(
                $user,
                $service,
                (int) $data['time_slot_id'],
                $claims,
            );
        } catch (SlotUnavailableException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (AppointmentConflictException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        // Sprint 6 sends the confirmation email/SMS from here.
        return redirect()->route('client.appointments.show', $appointment)
            ->with('success', 'Appointment confirmed. Your queue number is '.$appointment->queue_number.'.');
    }

    public function reschedule(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeOwnership($request, $appointment);

        $data = $request->validate([
            'time_slot_id' => ['required', 'integer'],
        ]);

        try {
            $updated = $this->booking->reschedule($appointment, (int) $data['time_slot_id']);
        } catch (SlotUnavailableException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (AppointmentConflictException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('client.appointments.show', $updated)
            ->with('success', 'Appointment moved. Your new queue number is '.$updated->queue_number.'.');
    }

    public function cancel(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeOwnership($request, $appointment);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($appointment->status->isClosed()) {
            return redirect()->route('client.appointments.show', $appointment)
                ->with('error', 'That appointment is already closed.');
        }

        $this->booking->cancel($appointment, $data['reason'] ?? null, $request->user());

        return redirect()->route('client.appointments.index')
            ->with('success', 'Appointment cancelled. Your slot has been released to other clients.');
    }

    /* ------------------------------------------------------------------ */

    private function authorizeOwnership(Request $request, Appointment $appointment): void
    {
        abort_unless(
            (int) $appointment->user_id === (int) $request->user()->id,
            403,
            'You can only change your own appointments.',
        );
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function activeBookingsFor($user, Service $service)
    {
        return Appointment::query()
            ->with('timeSlot')
            ->where('user_id', $user->id)
            ->where('service_id', $service->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Slots the client already holds, keyed by date, so the grid can mark them.
     *
     * @param  array<int, string>  $dates
     * @return array<string, array<int, int>>
     */
    private function bookedSlotIds($user, array $dates): array
    {
        $map = [];

        foreach ($dates as $date) {
            $map[$date] = $this->availability->bookedSlotIdsFor($user->id, $date);
        }

        return $map;
    }
}
