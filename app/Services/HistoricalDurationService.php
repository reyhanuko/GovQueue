<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Service;

/**
 * How long a service actually takes, measured rather than assumed.
 *
 * Decision D3: "use historical transaction times for wait estimates."
 *
 * services.duration_minutes is what an administrator typed when they set the
 * service up. It is a reasonable first guess and a poor description of reality:
 * a Barangay Clearance assumed at 20 minutes may really take 35, and a document
 * pickup assumed at 20 may take 8. A client told "about 20 minutes" is either
 * promised a wait the counter cannot keep or left standing at a counter that was
 * going to be free ten minutes ago.
 *
 * Every completed appointment already carries serving_started_at and
 * completed_at, so the real figure has been recorded all along and never used.
 *
 * WHEN HISTORY IS NOT USED
 *
 * A new service has no history, and a service nobody has completed yet has none
 * either. Both fall back to the configured duration, because "we do not know yet"
 * is not something to show a client. The fallback is marked as such in the
 * reporting, so an administrator can see that a figure is an assumption rather
 * than a measurement.
 *
 * WHY A MEDIAN, NOT A MEAN
 *
 * One appointment left open over lunch - serving_started_at at 11:58,
 * completed_at at 13:30 - is 92 minutes of real data and it wrecks a mean. A
 * median is unmoved by it, and it is also the number a client experiences: half
 * the people ahead of them were served faster than it.
 *
 * Only completed appointments with both stamps, and only where the end is after
 * the start. A clock correction or a manual edit can produce a negative
 * duration, and averaging those in would be nonsense.
 */
class HistoricalDurationService
{
    /** Below this many completed appointments, the guess is still the best answer. */
    private function minimumSamples(): int
    {
        return (int) config('govqueue.duration_min_samples', 5);
    }

    /**
     * The measured median duration for a service, in whole minutes.
     *
     * Rounded to the nearest minute because a client-facing figure with a
     * decimal is false precision: nobody's wait is 23.4 minutes.
     */
    public function medianMinutesFor(Service $service): ?int
    {
        $seconds = $this->medianSecondsFor($service);

        if ($seconds === null) {
            return null;
        }

        // Rounded once, at the end, from the raw seconds.
        return (int) round($seconds / 60);
    }

    /** The median transaction time in seconds, or null when unmeasurable. */
    private function medianSecondsFor(Service $service): ?float
    {
        return $this->samplesFor($service);
    }

    /**
     * The duration to quote: measured where possible, configured otherwise.
     *
     * @return array{minutes:int, measured:bool, samples:int}
     */
    public function forService(Service $service): array
    {
        $samples = $this->sampleCount($service);

        if ($samples >= $this->minimumSamples()) {
            $median = $this->medianMinutesFor($service);

            if ($median !== null) {
                return ['minutes' => $median, 'measured' => true, 'samples' => $samples];
            }
        }

        return [
            'minutes' => (int) ($service->duration_minutes ?: config('govqueue.default_service_minutes', 10)),
            'measured' => false,
            'samples' => $samples,
        ];
    }

    /**
     * The measured median duration across a window's services.
     *
     * Used by the staffing budget (decision D1), where the question is how many
     * clients a clerk can clear in a slot - which is a fact about what has
     * actually happened at that counter, not about what was typed into a form.
     */
    public function averageMinutesForWindow(int $windowId, int $fallbackMinutes = 10): float
    {
        $durations = [];

        foreach (Service::where('service_window_id', $windowId)->where('is_active', true)->get() as $service) {
            $median = $this->forService($service);

            $durations[] = $median['minutes'];
        }

        if ($durations === []) {
            return (float) $fallbackMinutes;
        }

        return round((float) array_sum($durations) / count($durations), 2);
    }

    /** Completed appointments with a usable duration, oldest irrelevant. */
    private function completedQuery(Service $service)
    {
        return Appointment::query()
            ->where('service_id', $service->id)
            ->whereNotNull('serving_started_at')
            ->whereNotNull('completed_at')
            // A duration must be positive to mean anything.
            ->whereRaw('completed_at > serving_started_at');
    }

    private function sampleCount(Service $service): int
    {
        return $this->completedQuery($service)->count();
    }

    private function samplesFor(Service $service): ?float
    {
        $durations = $this->completedQuery($service)
            ->selectRaw('TIMESTAMPDIFF(SECOND, serving_started_at, completed_at) AS seconds')
            ->pluck('seconds')
            ->filter(fn ($s) => $s !== null && (int) $s > 0)
            ->map(fn ($s) => (int) $s)
            ->sort()
            ->values();

        if ($durations->isEmpty()) {
            return null;
        }

        $middle = intdiv($durations->count(), 2);

        // Odd counts take the middle value; even counts average the two central
        // ones, which is what makes the result robust at the midpoint too.
        //
        // Seconds throughout, converted to minutes only at the very end by the
        // caller. Converting per-sample would round each one separately and a
        // spread of 12.4 and 12.6 minutes would become 12 and 13 - which is a
        // median of the rounded values rather than a rounded median, and quietly
        // biased upward.
        return $durations->count() % 2 === 1
            ? (float) $durations[$middle]
            : ($durations[$middle - 1] + $durations[$middle]) / 2;
    }
}
