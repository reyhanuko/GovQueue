<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Events\AppointmentStatusChanged;
use App\Events\QueueUpdated;
use App\Models\Appointment;
use App\Models\ServiceWindow;
use Illuminate\Support\Facades\Log;

/**
 * The one place queue changes are announced.
 *
 * Every listener subscribes to these events, so broadcast calls are collected
 * here rather than sprinkled through QueueService. That keeps two guarantees in
 * a single reviewable spot:
 *
 *  - a status change notifies the window board AND the client's private page;
 *  - broadcasting never breaks the request that triggered it. A lobby display
 *    failing to update must not stop a client from being served.
 */
class QueueBroadcaster
{
    public function __construct(private QueueService $queue) {}

    /**
     * Announce that a window's queue changed.
     */
    public function queueChanged(ServiceWindow $window, ?string $queueDate = null): void
    {
        $this->safely(fn () => QueueUpdated::dispatch(
            $window,
            $queueDate ?? today()->toDateString(),
        ));
    }

    /**
     * Announce a ticket's new status, with the client's live position and wait.
     */
    public function appointmentChanged(Appointment $appointment): void
    {
        $position = null;
        $estimated = null;

        if ($appointment->status->isActive() && $appointment->queue_date) {
            [$position, $estimated] = $this->locate($appointment);
        }

        $this->safely(fn () => AppointmentStatusChanged::dispatch(
            $appointment,
            $position,
            $estimated,
        ));

        // The relation is window(), not serviceWindow(): reading a
        // non-existent relation returned null and silently skipped the board
        // broadcast, which is exactly the kind of bug a null check hides.
        if ($appointment->window) {
            $this->queueChanged($appointment->window, $appointment->queue_date?->toDateString());
        }
    }

    /**
     * Announce a batch of tickets, used after bulk operations.
     *
     * @param  iterable<int, Appointment>  $appointments
     */
    public function appointmentsChanged(iterable $appointments): void
    {
        foreach ($appointments as $appointment) {
            $this->safely(fn () => AppointmentStatusChanged::dispatch($appointment));
        }
    }

    /**
     * Where this client sits in the line right now.
     *
     * @return array{0:?int, 1:?int} position (1-based) and estimated minutes
     */
    private function locate(Appointment $appointment): array
    {
        if (! $appointment->window) {
            return [null, null];
        }

        $queue = $appointment->status === AppointmentStatus::Serving
            ? collect([$appointment])
            : $this->queue->waitingFor($appointment->window, $appointment->queue_date?->toDateString());

        $queue = $queue->filter(fn (Appointment $a) => $a->status->isActive());

        $ids = $queue->pluck('id')->values();

        $position = $ids->search($appointment->id);

        if ($position === false) {
            return [null, null];
        }

        $estimates = $this->queue->estimateWaits($appointment->window, $queue);

        return [$position + 1, $estimates[$appointment->id] ?? null];
    }

    /**
     * Broadcasting is best-effort: never let it fail the request.
     */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('GovQueue broadcast failed (ignored): '.$e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
