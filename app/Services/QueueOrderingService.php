<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PriorityType;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single source of truth for "who is next in the queue".
 *
 * Every queue surface (staff console, client status page, public display) reads
 * its order through here, so the three can never disagree.
 *
 * Ordering rules, in priority order:
 *   1. Status stage - the client currently at the window, then tickets
 *      physically present (checked in, skipped), then clients not yet arrived.
 *   2. Verified statutory priority - RA 9994 / RA 10754 / pregnant clients.
 *      Unverified claims are treated as ordinary clients on purpose.
 *   3. Arrival time - checked_in_at, falling back to the slot start time and
 *      then booking time for walk-ins that were never checked in.
 *   4. Database id - final deterministic tie-break so the order is stable
 *      across queries and never flaps between requests.
 */
class QueueOrderingService
{
    /** Human-readable explanation of the rules, reused by the admin docs page. */
    public const RULE_DESCRIPTION = [
        '1. The client at the window is first, then those waiting at the counter, then anyone who asked to wait longer, then clients who have not arrived yet.',
        '2. Verified senior citizens, persons with disability, and pregnant clients come next (RA 9994, RA 10754).',
        '3. Within the same group, whoever arrived or booked earlier goes first. A client who asks to wait longer is served after the people still waiting.',
        '4. Ties are broken by database id (creation order) so the display order is stable.',
    ];

    /**
     * Apply the canonical ordering to a queue query.
     */
    public function applyOrder(Builder $query, ?string $queueDate = null): Builder
    {
        return $query
            ->orderByRaw($this->stageOrderSql())
            ->orderByRaw($this->priorityOrderSql())
            ->orderByRaw($this->arrivalOrderSql())
            // Earliest-created ticket wins a same-second tie, so a staff
            // member checking in two people back-to-back gets insertion
            // order rather than the booking-time reference number.
            ->orderBy('id');
    }

    /**
     * Sort an already-loaded collection. Mirrors applyOrder() so callers that
     * hold a collection (for example the wait-time estimator) behave the same.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return Collection<int, Appointment>
     */
    public function sortCollection(Collection $appointments): Collection
    {
        // Each criterion is a two-element list [comparator, 'asc']. A bare list
        // of closures is read as a key => callback map and silently sorts on
        // non-existent keys, which produced an arbitrary order before this.
        return $appointments->sortBy([
            [fn ($a, $b) => $this->stageRank($a) <=> $this->stageRank($b), 'asc'],
            [fn ($a, $b) => $this->priorityRank($a) <=> $this->priorityRank($b), 'asc'],
            [fn ($a, $b) => $this->arrivalTimestamp($a) <=> $this->arrivalTimestamp($b), 'asc'],
            [fn ($a, $b) => ($a->id && $b->id)
                ? ((int) $a->id <=> (int) $b->id)
                : ((string) $a->reference_number <=> (string) $b->reference_number), 'asc'],
        ])->values();
    }

    /* ------------------------------------------------------------------ */
    /* Ranking helpers - single definition used by SQL and by PHP */
    /* ------------------------------------------------------------------ */

    /**
     * 0 = at the window, 1 = waiting and ready, 2 = skipped and pushed back,
     * 3 = booked but not yet arrived.
     *
     * Skipped sits below CheckedIn deliberately. When a client says "not ready"
     * at the counter they go behind the people who are actually waiting, so one
     * absent client cannot hold the window while a queue builds up behind them.
     */
    public function stageRank($appointment): int
    {
        return match ($appointment->status) {
            AppointmentStatus::Serving => 0,
            AppointmentStatus::CheckedIn => 1,
            AppointmentStatus::Skipped => 2,
            default => 3,
        };
    }

    /** 0 = holds verified statutory priority, 1 = ordinary client. */
    public function priorityRank($appointment): int
    {
        return $appointment->hasVerifiedPriority() ? 0 : 1;
    }

    public function arrivalTimestamp($appointment): int
    {
        return ($appointment->checked_in_at
            ?? $appointment->timeSlot?->startDateTime()
            ?? $appointment->created_at)?->getTimestamp() ?? 0;
    }

    /**
     * Rank of a verified priority claim. Lower sorts earlier. PWD and pregnant
     * clients rank marginally ahead of senior citizens because they are served
     * quickly and their circumstances are time-sensitive; the ordering is
     * configurable in config/govqueue.php.
     *
     * @return array<string, int>
     */
    public function priorityWeights(): array
    {
        return [
            PriorityType::Pwd->value => config('govqueue.priority_weights.pwd', 10),
            PriorityType::Pregnant->value => config('govqueue.priority_weights.pregnant', 10),
            PriorityType::SeniorCitizen->value => config('govqueue.priority_weights.senior_citizen', 20),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Raw SQL fragments */
    /* ------------------------------------------------------------------ */

    private function stageOrderSql(): string
    {
        return "CASE status
                    WHEN 'serving' THEN 0
                    WHEN 'checked_in' THEN 1
                    WHEN 'skipped' THEN 2
                    ELSE 3
                END";
    }

    private function priorityOrderSql(): string
    {
        return 'CASE WHEN priority_verified_at IS NOT NULL
                AND (claims_senior_citizen = 1 OR claims_pwd = 1 OR claims_pregnant = 1)
                THEN 0 ELSE 1 END';
    }

    /**
     * COALESCE across the three arrival sources, all of which live on the
     * appointments table itself.
     */
    private function arrivalOrderSql(): string
    {
        return 'COALESCE(checked_in_at, scheduled_at, created_at)';
    }
}
