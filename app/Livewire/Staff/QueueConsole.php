<?php

namespace App\Livewire\Staff;

use App\Enums\AppointmentStatus;
use App\Exceptions\InvalidQueueTransitionException;
use App\Models\Appointment;
use App\Models\Appointment as Ticket;
use App\Models\ServiceWindow;
use App\Services\QueueService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The live counter console.
 *
 * Everything happens in-place: call the next ticket, serve, complete, skip, or
 * verify a priority claim, without a page reload. Order comes from
 * QueueOrderingService so statutory priority is applied automatically instead of
 * depending on the operator's memory of who is a senior citizen.
 */
class QueueConsole extends Component
{
    #[Url(as: 'date', keep: false)]
    public string $queueDate = '';

    #[Url(as: 'w')]
    public string $windowCode = '';

    /** Ticket selected for the priority-verification panel. */
    public ?int $verifyingId = null;

    public ?int $servingId = null;

    public ?int $completingId = null;

    public string $transactionNote = '';

    public string $feePaid = '';

    public string $priorityNote = '';

    public ?string $feedback = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->queueDate = $this->queueDate ?: today()->toDateString();
        $this->windowCode = $this->windowCode ?: (ServiceWindow::where('is_active', true)->value('code') ?? '');
    }

    public function updatedWindowCode(): void
    {
        // A different window means a different queue; drop any open panel.
        $this->reset(['servingId', 'completingId', 'verifyingId']);
    }

    /* ------------------------------------------------------------------ */
    /* Computed views */
    /* ------------------------------------------------------------------ */

    #[Computed]
    public function windows(): Collection
    {
        return ServiceWindow::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function window(): ?ServiceWindow
    {
        return ServiceWindow::where('code', $this->windowCode)->first();
    }

    #[Computed]
    public function serving(): ?Ticket
    {
        return $this->window()
            ? $this->queue()->currentlyServing($this->window(), $this->queueDate)
            : null;
    }

    #[Computed]
    public function waiting(): Collection
    {
        if (! $this->window()) {
            return collect();
        }

        return $this->queue()->waitingFor($this->window(), $this->queueDate);
    }

    #[Computed]
    public function expected(): Collection
    {
        if (! $this->window()) {
            return collect();
        }

        return $this->queue()->expectedButNotArrived($this->window(), $this->queueDate)
            ->take(8);
    }

    #[Computed]
    public function estimates(): array
    {
        if (! $this->window()) {
            return [];
        }

        return $this->queue()->estimateWaits($this->window(), $this->waiting());
    }

    #[Computed]
    public function stats(): array
    {
        $base = Appointment::forWindow($this->window()?->id ?? 0)
            ->whereDate('queue_date', $this->queueDate);

        return [
            'served' => (clone $base)->where('status', AppointmentStatus::Completed->value)->count(),
            'no_shows' => (clone $base)->where('status', AppointmentStatus::NoShow->value)->count(),
            'waiting' => $this->waiting()->count(),
            'priority' => $this->waiting()->filter->hasVerifiedPriority()->count(),
            'avg_service' => $this->window()
                ? round($this->queue()->averageServiceMinutes($this->window()), 1)
                : 0,
        ];
    }

    #[Computed]
    public function verifying(): ?Ticket
    {
        return $this->verifyingId
            ? Appointment::with(['user', 'service'])->find($this->verifyingId)
            : null;
    }

    public function queue(): QueueService
    {
        return app(QueueService::class);
    }

    public function setDate(string $date): void
    {
        $this->queueDate = $date;
        $this->reset(['servingId', 'completingId', 'verifyingId']);
    }

    /* ------------------------------------------------------------------ */
    /* Actions */
    /* ------------------------------------------------------------------ */

    public function callNext(): void
    {
        $window = $this->window();

        if (! $window) {
            $this->error = 'No service window is selected.';

            return;
        }

        $actor = auth()->user();

        try {
            $called = $this->queue()->callNext($window, $actor, $this->queueDate);
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        if ($called === null) {
            $this->error = $this->serving
                ? 'Finish serving the current client before calling the next one.'
                : 'Nobody is waiting at this window.';

            return;
        }

        $this->feedback = 'Now serving '.$called->queue_number.' - '.$called->user->name;
        $this->error = null;
    }

    /**
     * Bring a client who has not checked in yet into the line.
     */
    public function checkIn(int $appointmentId): void
    {
        $appointment = Appointment::findOrFail($appointmentId);

        try {
            $this->guardWindow($appointment);
            $this->queue()->checkIn($appointment, auth()->user());
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->feedback = $appointment->queue_number.' checked in.';
        $this->error = null;
    }

    public function skip(int $appointmentId): void
    {
        $appointment = Appointment::findOrFail($appointmentId);

        try {
            $this->guardWindow($appointment);
            $this->queue()->skip($appointment, auth()->user());
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->feedback = $appointment->queue_number.' moved back in the line.';

        if ($this->queue()->hasBeenSkippedTooOften($appointment->fresh())) {
            $this->feedback .= ' Skipped '.config('govqueue.max_skips')
                .' times - consider marking this client a no-show.';
        }

        $this->error = null;
    }

    public function openComplete(int $appointmentId): void
    {
        $this->guardWindow(Appointment::findOrFail($appointmentId));

        $this->completingId = $appointmentId;
        $this->transactionNote = '';
        $this->feePaid = (string) Appointment::findOrFail($appointmentId)->service->fee;
    }

    public function complete(): void
    {
        if (! $this->completingId) {
            return;
        }

        $appointment = Appointment::findOrFail($this->completingId);

        try {
            $this->guardWindow($appointment);
            $this->queue()->complete($appointment, auth()->user(), [
                'details' => $this->transactionNote !== '' ? ['note' => $this->transactionNote] : null,
                'fee_paid' => $this->feePaid !== '' ? (float) $this->feePaid : null,
            ]);
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->feedback = 'Transaction recorded for '.$appointment->queue_number.'.';
        $this->error = null;
        $this->completingId = null;
    }

    public function markNoShow(): void
    {
        $serving = $this->serving;

        if (! $serving) {
            return;
        }

        try {
            $this->queue()->markNoShow($serving, auth()->user());
        } catch (InvalidQueueTransitionException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->feedback = $serving->queue_number.' marked as a no-show.';
        $this->error = null;
    }

    public function openVerify(int $appointmentId): void
    {
        $appointment = Appointment::findOrFail($appointmentId);
        $this->guardWindow($appointment);

        $this->verifyingId = $appointmentId;
        $this->priorityNote = $appointment->priority_reference ?? '';
    }

    public function verifyPriority(): void
    {
        if (! $this->verifyingId) {
            return;
        }

        $appointment = Appointment::findOrFail($this->verifyingId);

        if ($appointment->claimedPriorities() === []) {
            $this->error = 'This client has not claimed any priority category.';

            return;
        }

        $this->queue()->verifyPriority($appointment, auth()->user(), $this->priorityNote ?: null);

        $this->feedback = 'Priority verified for '.$appointment->queue_number.'. Reordering the queue.';
        $this->error = null;
        $this->verifyingId = null;
    }

    public function refusePriority(): void
    {
        if (! $this->verifyingId) {
            return;
        }

        $appointment = Appointment::findOrFail($this->verifyingId);

        $this->queue()->refusePriority(
            $appointment,
            auth()->user(),
            $this->priorityNote !== '' ? $this->priorityNote : 'No supporting document presented',
        );

        $this->feedback = 'Priority claim refused for '.$appointment->queue_number.'.';
        $this->error = null;
        $this->verifyingId = null;
    }

    public function dismissMessages(): void
    {
        $this->feedback = null;
        $this->error = null;
    }

    /**
     * Refuse to act on a ticket that belongs to a different window.
     */
    private function guardWindow(Appointment $appointment): void
    {
        if ($this->window() && (int) $appointment->service_window_id !== (int) $this->window()->id) {
            abort(403, 'That ticket belongs to a different service window.');
        }
    }

    public function render()
    {
        return view('livewire.staff.queue-console');
    }
}
