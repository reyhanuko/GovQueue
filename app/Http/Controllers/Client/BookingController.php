<?php

namespace App\Http\Controllers\Client;

use App\Enums\PriorityType;
use App\Exceptions\AppointmentConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Concerns\ResolvesBookingDay;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\BookingService;
use App\Services\HistoricalDurationService;
use App\Services\NoShowPolicy;
use App\Services\PriorityDocumentStore;
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

        /*
         * The no-show block (decision D6).
         *
         * The page and the server have to agree: the service refuses a booking
         * from a blocked client, so the screen says so too rather than
         * offering a form that cannot be submitted.
         */
        $policy = app(NoShowPolicy::class);

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
            'bookingBlocked' => $policy->isBlocked($user),
            'bookingBlockedUntil' => $policy->blockedUntil($user),
            'bookingBlockMessage' => $policy->blockMessage($user),
            /*
             * How long this service really takes (decision D3).
             *
             * services.duration_minutes is what an administrator typed when the
             * service was set up. Every completed appointment already records
             * serving_started_at and completed_at, so the measured figure exists,
             * and quoting the typed one to a client who is deciding whether to
             * travel is quoting a guess.
             *
             * Falls back to the configured duration for a service with too little
             * history to measure - "we do not know yet" is not something to show
             * someone.
             */
            'duration' => app(HistoricalDurationService::class)->forService($service),
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
            'priority_reference_pwd' => ['nullable', 'string', 'max:120'],
            'priority_reference_pregnant' => ['nullable', 'string', 'max:120'],
            'priority_document_pwd' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:'.PriorityDocumentStore::MAX_KILOBYTES,
            ],
            'priority_document_pregnant' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:'.PriorityDocumentStore::MAX_KILOBYTES,
            ],
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

        /*
         * One reference per claim, kept together on the ticket.
         *
         * A client may claim PWD and pregnancy at the same time, and each has
         * its own document, so each has its own reference. A single field for
         * both made the client write two numbers into one box, and the staff
         * had no way to tell which was which.
         */
        $references = [];

        if ($claims['claims_pwd']) {
            $references[PriorityType::Pwd->value] = $data['priority_reference_pwd'] ?? null;
        }

        if ($claims['claims_pregnant']) {
            $references[PriorityType::Pregnant->value] = $data['priority_reference_pregnant'] ?? null;
        }

        foreach ($references as $category => $reference) {
            if (blank($reference)) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'priority_reference_'.$category => 'Please enter the reference for this claim so staff can verify it.',
                    ]);
            }
        }

        $claims['priority_reference'] = PriorityType::joinReferences($references);

        /*
         * The soft copies of the documents, one per claim.
         *
         * A client may claim more than one category, and each category is
         * made on a different document - a PWD ID and a medical certificate.
         * One file for two claims is a file the clerk cannot match to a
         * claim, which is the same mistake as one reference field for two
         * claims.
         *
         * Stored before the booking, because the booking needs the paths to
         * record. If the booking then fails, the files are removed again: a
         * document nobody claimed is a client's identity document sitting on a
         * disk with nothing pointing at it.
         */
        $documents = app(PriorityDocumentStore::class);
        $stored = [];

        foreach ([
            PriorityType::Pwd->value => 'priority_document_pwd',
            PriorityType::Pregnant->value => 'priority_document_pregnant',
        ] as $category => $field) {
            if (! $request->hasFile($field)) {
                continue;
            }

            try {
                $stored[] = $documents->store(
                    $request->file($field),
                    PriorityType::from($category),
                );
            } catch (SlotUnavailableException $e) {
                // The files already stored have to go too: the booking is
                // not happening, and neither is any of them.
                foreach ($stored as $document) {
                    $documents->delete($document['path']);
                }

                return back()->withInput()->withErrors([$field => $e->getMessage()]);
            }
        }

        try {
            $appointment = $this->booking->book(
                $user,
                $service,
                (int) $data['time_slot_id'],
                $claims + [
                    'priority_documents' => $stored,
                ],
            );
        } catch (SlotUnavailableException $e) {
            $this->deleteDocuments($documents, $stored);

            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (AppointmentConflictException $e) {
            $this->deleteDocuments($documents, $stored);

            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        // Sprint 6 sends the confirmation email/SMS from here.
        return redirect()->route('client.appointments.show', $appointment)
            ->with('success', 'Appointment confirmed. Your queue number is '.$appointment->queue_number.'.');
    }

    /**
     * Remove the documents of a booking that did not happen.
     *
     * A file on the disk with nothing pointing at it is a client's identity
     * document that nobody claimed and nobody is responsible for.
     *
     * @param  array<int, array{path: string}>  $stored
     */
    private function deleteDocuments(PriorityDocumentStore $documents, array $stored): void
    {
        foreach ($stored as $document) {
            $documents->delete($document['path']);
        }
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

        try {
            $this->booking->cancel($appointment, $data['reason'] ?? null, $request->user());
        } catch (AppointmentConflictException $e) {
            /*
             * The client is not allowed to cancel this one - they have already
             * arrived, and releasing the slot would let them walk out of a
             * queue position without becoming a no-show.
             *
             * The page no longer offers the button, so this is here for a
             * client with a stale tab, a bookmarked form, or a request they
             * made before they arrived. It used to be uncaught, which turned a
             * rule into a 500 error page.
             */
            return redirect()->route('client.appointments.show', $appointment)
                ->with('error', $e->getMessage());
        }

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
