<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Events\AppointmentStatusChanged;
use App\Events\QueueUpdated;
use App\Models\Appointment;
use App\Models\ServiceWindow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     *
     * A circuit breaker sits in front of it because GovQueue's queue events are
     * broadcast synchronously (the lobby display must update the moment staff call
     * a number), which makes the socket part of a client's request latency. With
     * Reverb down, each booking fired two triggers that each burned a full connect
     * timeout - measured at 2.7s per booking. After the first failure the
     * broadcaster stops trying for a short cooldown, so a dead Reverb costs a
     * single slow request rather than one per action.
     */
    private function safely(callable $callback): void
    {
        // The breaker reads the cache, and GovQueue's cache is the database -
        // the same store that is most likely to be the thing that is broken.
        // So the check is inside the try, and a cache failure simply means the
        // breaker cannot vouch for anything and we attempt the broadcast anyway.
        try {
            if ($this->isTripped()) {
                return;
            }

            $callback();

            $this->recordSuccess();
        } catch (\Throwable $e) {
            $this->recordFailure($e);

            Log::warning('GovQueue broadcast failed (ignored): '.$e->getMessage(), [
                'exception' => $e,
                'cooldown_seconds' => $this->cooldown(),
            ]);
        }
    }

    /**
     * Has the socket recently failed?
     */
    private function isTripped(): bool
    {
        try {
            return Cache::get($this->breakerKey()) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget($this->breakerKey());
        } catch (\Throwable) {
            // A stale breaker entry expires on its own; nothing to do.
        }
    }

    private function recordFailure(\Throwable $e): void
    {
        try {
            Cache::put($this->breakerKey(), [
                'failed_at' => now()->toIso8601String(),
                'message' => Str::limit($e->getMessage(), 200),
            ], $this->cooldown());
        } catch (\Throwable) {
            // Without a working cache we simply retry on the next request.
        }
    }

    private function breakerKey(): string
    {
        return 'govqueue:broadcast-breaker:'.config('broadcasting.default');
    }

    private function cooldown(): int
    {
        return (int) config('govqueue.broadcast_failure_cooldown', 30);
    }

    /**
     * Is the broadcaster believed to be usable right now?
     *
     * Surfaced on the admin screen so staff understand why the display is not
     * updating instead of assuming the system is broken.
     */
    public function isHealthy(): bool
    {
        return ! $this->isTripped();
    }
}
