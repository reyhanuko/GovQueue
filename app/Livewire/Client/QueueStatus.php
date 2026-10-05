<?php

namespace App\Livewire\Client;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\QueueService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A client's live view of their own ticket.
 *
 * Subscribes to the private channels for this appointment and for the client's
 * other tickets, so the position and estimated wait update the moment staff act
 * rather than on a refresh.
 */
class QueueStatus extends Component
{
    public int $appointmentId;

    /** Position in the line, 1-based. Null once no longer queued. */
    public ?int $position = null;

    public ?int $estimatedWaitMinutes = null;

    public ?string $status = null;

    public ?string $queueNumber = null;

    /** Set when a push arrives, purely to flash the card for the user. */
    public bool $justUpdated = false;

    public function mount(Appointment $appointment): void
    {
        $this->appointmentId = $appointment->id;
        $this->status = $appointment->status->value;
        $this->queueNumber = $appointment->queue_number;

        $this->refreshPosition();
    }

    /**
     * Payload from AppointmentStatusChanged.
     *
     * Invoked directly by the Echo listener in resources/js/govqueue.js rather
     * than through Livewire's `echo:` event matching, which does not line up with
     * the comma-separated event names Echo uses.
     */
    public function onStatusChanged(array $payload = []): void
    {
        if ((int) ($payload['id'] ?? 0) !== $this->appointmentId) {
            return;
        }

        $this->status = $payload['status'] ?? $this->status;
        $this->queueNumber = $payload['queue_number'] ?? $this->queueNumber;
        $this->position = $payload['position'] ?? null;
        $this->estimatedWaitMinutes = $payload['estimated_wait_minutes'] ?? null;
        $this->justUpdated = true;
    }

    public function dismissFlash(): void
    {
        $this->justUpdated = false;
    }

    /**
     * Recompute position and wait from the database.
     */
    public function refreshPosition(): void
    {
        $appointment = Appointment::with(['window', 'timeSlot', 'service'])->find($this->appointmentId);

        if (! $appointment) {
            $this->position = null;
            $this->estimatedWaitMinutes = null;

            return;
        }

        $this->status = $appointment->status->value;
        $this->queueNumber = $appointment->queue_number;

        if (! $appointment->status->isActive() || ! $appointment->window) {
            $this->position = null;
            $this->estimatedWaitMinutes = null;

            return;
        }

        $queue = app(QueueService::class)->waitingFor(
            $appointment->window,
            $appointment->queue_date?->toDateString(),
        )->filter(fn (Appointment $a) => $a->status->isActive());

        $index = $queue->pluck('id')->search($appointment->id);

        if ($index === false) {
            $this->position = null;
            $this->estimatedWaitMinutes = null;

            return;
        }

        $this->position = $index + 1;
        $this->estimatedWaitMinutes = app(QueueService::class)
            ->estimateWaits($appointment->window, $queue)[$appointment->id] ?? null;
    }

    #[Computed]
    public function appointment(): ?Appointment
    {
        return Appointment::with(['service', 'window', 'timeSlot'])->find($this->appointmentId);
    }

    #[Computed]
    public function statusEnum(): ?AppointmentStatus
    {
        return $this->status ? AppointmentStatus::tryFrom($this->status) : null;
    }

    /**
     * How many people are ahead of this client, inclusive of themselves.
     *
     * Shown as "n of total" so the client can see the queue move, not just their
     * own number.
     */
    #[Computed]
    public function queueTotal(): int
    {
        $appointment = $this->appointment;

        if (! $appointment || ! $appointment->window || ! $appointment->status->isActive()) {
            return 0;
        }

        return app(QueueService::class)
            ->waitingFor($appointment->window, $appointment->queue_date?->toDateString())
            ->filter(fn (Appointment $a) => $a->status->isActive())
            ->count();
    }

    #[Computed]
    public function waitLabel(): ?string
    {
        if ($this->position === null) {
            return null;
        }

        if ($this->position === 1 && ($this->estimatedWaitMinutes ?? 0) === 0) {
            return 'You are next';
        }

        $minutes = $this->estimatedWaitMinutes ?? 0;

        if ($minutes < 1) {
            return 'You are next';
        }

        if ($minutes < 60) {
            return 'About '.$minutes.' '.Str::plural('minute', $minutes).' wait';
        }

        $hours = (int) floor($minutes / 60);
        $rest = $minutes % 60;

        return trim('About '.$hours.' '.Str::plural('hour', $hours).($rest ? ' '.$rest.' min' : '').' wait');
    }

    public function render()
    {
        return view('livewire.client.queue-status');
    }
}
