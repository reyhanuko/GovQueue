<?php

namespace App\Services;

use App\Contracts\QueueReader;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use Illuminate\Support\Collection;

/**
 * What a window's queue looks like right now.
 *
 * The read half of the counter, split from QueueService so the announcer can read
 * the queue without depending on the service that changes it. See
 * App\Contracts\QueueReader for why the cycle had to be broken by direction rather
 * than by renaming a class.
 *
 * Nothing here mutates. That is the whole contract, and it is what lets the lobby
 * display, a client's own page, and the announcer all share these queries without
 * any of them gaining the ability to move a ticket.
 *
 * Order comes from QueueOrderingService through the model's scope, so these
 * queries cannot disagree with each other or with the staff console about who is
 * next.
 */
class QueueReaderService implements QueueReader
{
    /**
     * Everyone physically at the counter, in service order.
     *
     * @return Collection<int, Appointment>
     */
    public function waitingFor(ServiceWindow $window, ?string $queueDate = null): Collection
    {
        $queueDate = $queueDate ?? today()->toDateString();

        // Canonical order first, then the statutory re-insertion for skipped
        // priority clients. Applied in the one place every consumer reads from,
        // so the console, the client board and the public display cannot
        // disagree about who is next.
        return app(QueueOrderingService::class)
            ->applyPriorityReinsertion(
                Appointment::with(['user', 'service'])
                    ->forWindow($window->id)
                    ->whereDate('queue_date', $queueDate)
                    ->whereIn('status', [
                        AppointmentStatus::CheckedIn->value,
                        AppointmentStatus::Skipped->value,
                    ])
                    ->inQueueOrder($queueDate)
                    ->get()
            );
    }

    /**
     * The ticket currently at the window, if any.
     */
    public function currentlyServing(ServiceWindow $window, ?string $queueDate = null): ?Appointment
    {
        return Appointment::with(['user', 'service'])
            ->forWindow($window->id)
            ->whereDate('queue_date', $queueDate ?? today())
            ->where('status', AppointmentStatus::Serving->value)
            ->first();
    }

    /**
     * Clients who have a booking on this day but have not checked in yet.
     *
     * @return Collection<int, Appointment>
     */
    public function expectedButNotArrived(ServiceWindow $window, ?string $queueDate = null): Collection
    {
        return Appointment::with(['user', 'service', 'timeSlot'])
            ->forWindow($window->id)
            ->whereDate('queue_date', $queueDate ?? today())
            ->where('status', AppointmentStatus::Confirmed->value)
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Minutes each waiting client should expect to wait, keyed by appointment id.
     *
     * An estimate for display only: the project does not promise a committed wait
     * time, so this scales with position and the window's measured average rather
     * than promising anything.
     *
     * $perClientMinutes lets a caller that already knows the average pass it in. The
     * staff console shows the average and every client's estimate on one screen, so
     * without this the 30-day measurement ran twice per render - and because the
     * container hands out a fresh instance per resolution there was no object on
     * which to memoise it. Passing it in also guarantees the headline figure and
     * the estimates under it derive from the same measurement.
     *
     * @param  Collection<int, Appointment>  $queue
     * @return array<int, int|null>
     */
    public function estimateWaits(ServiceWindow $window, Collection $queue, ?float $perClientMinutes = null): array
    {
        if ($queue->isEmpty()) {
            return [];
        }

        $perClient = $perClientMinutes ?? $this->averageServiceMinutes($window);

        $estimates = [];
        $position = 0;

        foreach ($queue as $appointment) {
            $estimates[$appointment->id] = $position === 0
                ? 0
                : (int) round($position * $perClient);
            $position++;
        }

        return $estimates;
    }

    /**
     * Measured average minutes per client for a window, over recent history.
     *
     * Averaged in SQL with TIMESTAMPDIFF rather than by loading rows into PHP. This
     * reads 30 days of completed history, which on a busy window is thousands of
     * rows that were being hydrated into Eloquent models only to subtract two
     * timestamps. MySQL is the production driver and the one the suite runs
     * against, so there is no portability gap being traded away.
     */
    public function averageServiceMinutes(ServiceWindow $window): float
    {
        $row = Appointment::forWindow($window->id)
            ->where('status', AppointmentStatus::Completed->value)
            ->whereNotNull('serving_started_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays(30))
            ->selectRaw('COUNT(*) as samples')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, serving_started_at, completed_at)) as average')
            ->first();

        $samples = (int) ($row->samples ?? 0);
        $measured = (float) ($row->average ?? 0);

        // Below this many observations the sample is too thin to trust, and a zero
        // average means the timestamps were not recorded usefully. Either way we
        // fall back to a figure derived from the window's own services, which is
        // what the estimate should reflect when there is no history to measure.
        return ($samples >= 5 && $measured > 0)
            ? $measured
            : $this->derivedAverageMinutes($window);
    }

    /**
     * Memoised derived average, per window, for the life of this instance.
     *
     * The staff console asks for the average and every client's estimate on one
     * screen, so without this the service-durations query ran several times per
     * render. The container hands out a fresh instance per resolution, so
     * "per instance" is "per request" here - which is exactly the lifetime this
     * cache is valid for.
     *
     * @var array<int, float>
     */
    private array $derivedAverages = [];

    /**
     * Fallback service time when there is not enough history to measure.
     *
     * The window's own figure is used when it has been set deliberately and
     * agrees with its services; otherwise the mean duration of the window's
     * active services is used.
     *
     * The disagreement matters more than it looks: Window 2 configured 15
     * minutes while its services take 20 and 30, so every wait estimate it
     * showed was roughly half what the client would actually wait. A number that
     * is plainly wrong is worse than no number at all, so it is not trusted on
     * its own.
     */
    public function derivedAverageMinutes(ServiceWindow $window): float
    {
        if (isset($this->derivedAverages[$window->id])) {
            return $this->derivedAverages[$window->id];
        }

        $durations = Service::where('service_window_id', $window->id)
            ->where('is_active', true)
            ->where('duration_minutes', '>', 0)
            ->pluck('duration_minutes');

        if ($durations->isEmpty()) {
            return $this->derivedAverages[$window->id] = (float) $window->avg_service_minutes;
        }

        $derived = (float) $durations->avg();
        $configured = (float) $window->avg_service_minutes;

        // The configured figure is trusted only when it sits within a minute of
        // the services' own durations.
        $result = ($configured <= 0)
            ? $derived
            : (($configured >= $derived - 1 && $configured <= $derived + 1) ? $configured : $derived);

        return $this->derivedAverages[$window->id] = $result;
    }

    /**
     * Where this client sits in the line, and how long to expect.
     *
     * @return array{0:?int, 1:?int} position (1-based) and estimated minutes
     */
    public function locate(Appointment $appointment): array
    {
        if (! $appointment->window) {
            return [null, null];
        }

        /*
         * The queue the client is standing in.
         *
         * There used to be a branch here that treated a ticket being served as
         * a queue of one, so that it came back as position 1. It was dead:
         * the filter below removed the very appointment the branch had put
         * there, and the caller only asks for a position for a ticket that is
         * still in the line. It was also wrong - position 1 is what the client
         * board renders as "You are next", and a client whose number has been
         * called is not next, they are at the counter.
         */
        $queue = $this->waitingFor($appointment->window, $appointment->queue_date?->toDateString())
            ->filter(fn (Appointment $a) => $a->status->isInLine());

        $position = $queue->pluck('id')->values()->search($appointment->id);

        if ($position === false) {
            return [null, null];
        }

        $estimates = $this->estimateWaits($appointment->window, $queue);

        return [$position + 1, $estimates[$appointment->id] ?? null];
    }
}
