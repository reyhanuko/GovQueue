<?php

namespace App\Events;

use App\Models\ServiceWindow;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The queue for one window on one day changed.
 *
 * Broadcast on a public channel because the digital display in the lobby is
 * deliberately unauthenticated: it shows queue numbers only, never client names
 * or service details.
 *
 * Dispatched immediately rather than queued (ShouldBroadcastNow): the payload is
 * a few dozen bytes and the whole point is low latency, while making the public
 * display depend on a running queue worker is a fragile requirement for a small
 * municipal deployment. Outbound email and SMS in Sprint 6 stay queued, where
 * the delay is acceptable.
 */
class QueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ServiceWindow $window,
        public string $queueDate,
    ) {}

    /**
     * Channel name without the private- prefix.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('queue.'.$this->window->code);
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }

    /**
     * A minimal payload: the listeners refetch the board, so sending the whole
     * queue here would duplicate the query work on every client.
     */
    public function broadcastWith(): array
    {
        return [
            'window' => $this->window->code,
            'window_name' => $this->window->name,
            'queue_date' => $this->queueDate,
            'at' => now()->toIso8601String(),
        ];
    }
}
