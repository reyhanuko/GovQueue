<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One client's ticket changed status, so their personal status page can update.
 *
 * Private channel: the payload is scoped to a single client and must never be
 * readable by anyone else on the lobby network.
 *
 * Sent immediately rather than queued, for the same reason as QueueUpdated: a
 * client watching their own number needs low latency, and the payload is tiny.
 */
class AppointmentStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public ?int $position = null,
        public ?int $estimatedWaitMinutes = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('appointment.'.$this->appointment->id),
            new PrivateChannel('client.'.$this->appointment->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'appointment.status';
    }

    /**
     * Only what the client is entitled to see about their own ticket.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->appointment->id,
            'reference' => $this->appointment->reference_number,
            'status' => $this->appointment->status->value,
            'status_label' => $this->appointment->status->label(),
            'queue_number' => $this->appointment->queue_number,
            'position' => $this->position,
            'estimated_wait_minutes' => $this->estimatedWaitMinutes,
            'at' => now()->toIso8601String(),
        ];
    }
}
