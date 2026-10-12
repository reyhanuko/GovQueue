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
        //
        // The skipped-priority re-insertion runs here too, so this mirrors
        // applyOrder() + applyPriorityReinsertion() exactly.
        return $this->applyPriorityReinsertion(
            $appointments->sortBy([
                [fn ($a, $b) => $this->stageRank($a) <=> $this->stageRank($b), 'asc'],
                [fn ($a, $b) => $this->priorityRank($a) <=> $this->priorityRank($b), 'asc'],
                [fn ($a, $b) => $this->arrivalTimestamp($a) <=> $this->arrivalTimestamp($b), 'asc'],
                [fn ($a, $b) => ($a->id && $b->id)
                    ? ((int) $a->id <=> (int) $b->id)
                    : ((string) $a->reference_number <=> (string) $b->reference_number), 'asc'],
            ])->values()
        );
    }

    /**
     * Move skipped verified-priority clients back to near the front.
     *
     * Skipping is correct for an ordinary ticket: a client who was not at the
     * window goes behind the people who are. For a verified senior citizen or
     * PWD client it is not, because they are there under statute (RA 9994 /
     * RA 10754) and the next person served may have arrived after them.
     *
     * So a skipped verified-priority client is re-inserted after at most
     * config('govqueue.priority_skip_reinsert_after') clients, and stays ahead
     * of everyone beyond that. Regular skipped clients are untouched.
     *
     * Applied here, after the canonical order, rather than as another SQL CASE:
     * the rule depends on how many people are in the line, which a per-row
     * ranking expression cannot see. Doing it in one place also means the SQL
     * path and the PHP path cannot disagree about it.
     *
     * @param  Collection<int, Appointment>  $ordered  already in queue order
     * @return Collection<int, Appointment>
     */
    public function applyPriorityReinsertion(Collection $ordered): Collection
    {
        $keep = (int) config('govqueue.priority_skip_reinsert_after', 2);

        if ($keep < 1 || $ordered->isEmpty()) {
            return $ordered->values();
        }

        $items = $ordered->values()->all();

        /*
         * Collect first, then place.
         *
         * The previous version spliced in a single backwards loop, reasoning
         * that moving one ticket would not shift the indices of the ones still to
         * be processed. It does: splicing at $target shifts everything in
         * [target, i-1] up by one, so the index the cursor reached next held a
         * different ticket than intended.
         *
         * With one skipped priority client - the case every existing test covered
         * - the loop happened to land on the only ticket it needed. With two, the
         * second was skipped entirely: it stayed at the very back, behind an
         * ordinary client who arrived later, and the two statutory tickets came
         * out reversed against each other.
         *
         * Deciding the whole set up front and writing them in afterwards makes
         * the operation order-independent. Each ticket's target is computed from
         * the ORIGINAL index, so a ticket that is already near the front does not
         * get pushed forward by an earlier move and then counted twice.
         */
        $moves = [];

        foreach ($items as $i => $ticket) {
            if (! $this->isSkippedPriority($ticket)) {
                continue;
            }

            $target = min($i, $keep);

            if ($target === $i) {
                continue;
            }

            $moves[] = ['from' => $i, 'to' => $target];
        }

        /*
         * Remove them all, then put them back.
         *
         * Interleaving removals and insertions cannot work: every removal shifts
         * the indices of everything after it, so a second move computed from the
         * original ordering would act on the wrong element. Separating the two
         * phases means every index is read from the same list.
         *
         * Removals run from the back so each removal leaves the earlier indices
         * alone.
         *
         * Insertions run by target ascending, and by ORIGINAL index DESCENDING
         * within a target. The descending tiebreak is what preserves relative
         * order for two statutory tickets competing for the same slot: the one
         * that was later in the canonical queue is inserted first, so the earlier
         * one lands in front of it. Inserting them the other way round silently
         * reversed the pair, which is precisely what this whole pass was meant to
         * prevent.
         */
        usort($moves, fn (array $a, array $b) => $b['from'] <=> $a['from']);

        foreach ($moves as $move) {
            array_splice($items, $move['from'], 1);
        }

        usort($moves, fn (array $a, array $b) => [$a['to'], $b['from']] <=> [$b['to'], $a['from']]);

        foreach ($moves as $move) {
            array_splice($items, $move['to'], 0, [$ordered[$move['from']]]);
        }

        return collect($items);
    }

    /** Skipped, and holding a verified statutory claim. */
    private function isSkippedPriority(Appointment $appointment): bool
    {
        return $appointment->status === AppointmentStatus::Skipped
            && $appointment->hasVerifiedPriority();
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

    /**
     * When a client arrived, as a comparable timestamp.
     *
     * Deliberately the same two columns as arrivalOrderSql(): checked_in_at for
     * anybody physically present, created_at for a ticket booked ahead of time.
     *
     * The timeSlot fallback this used to have in the middle is gone. It made the
     * PHP and SQL definitions of "arrival" disagree for walk-ins - which have
     * scheduled_at = null - so the same people could be shown in one order on the
     * client board and another on the lobby display. `timeSlot` is also not
     * eager-loaded here, so every comparison inside the sort was a lazy load: one
     * sort of twenty clients issued twenty extra queries.
     */
    public function arrivalTimestamp($appointment): int
    {
        return ($appointment->checked_in_at
            ?? $appointment->created_at)?->getTimestamp() ?? 0;
    }

    /**
     * Rank of a verified priority claim. Lower sorts earlier.
     *
     * Uses the weights from config/govqueue.php rather than a flat 0/1. It was
     * flat, which made the whole configuration dead: the config file and this
     * method's own docblock both promise that PWD and pregnant clients rank
     * ahead of senior citizens, and in practice every verified claim tied and
     * was separated only by arrival time - so a senior citizen who arrived
     * earlier was served first whatever the office had configured.
     *
     * A client claiming several categories takes the best (lowest) weight they
     * hold, so a senior citizen who is also a PWD client ranks as a PWD client.
     * No verified claim at all returns a value worse than any real weight, so
     * ordinary clients always sort after.
     */
    public function priorityRank($appointment): int
    {
        if (! $appointment->hasVerifiedPriority()) {
            return max($this->priorityWeights()) + 1;
        }

        $weights = $this->priorityWeights();

        $best = max($weights) + 1;

        foreach ($appointment->claimedPriorities() as $type) {
            $best = min($best, $weights[$type->value] ?? $best);
        }

        return $best;
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
            PriorityType::Pwd->value => (int) config('govqueue.priority_weights.pwd', 10),
            PriorityType::Pregnant->value => (int) config('govqueue.priority_weights.pregnant', 10),
            PriorityType::SeniorCitizen->value => (int) config('govqueue.priority_weights.senior_citizen', 20),
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

    /**
     * The SQL twin of priorityRank().
     *
     * Built from the same configured weights rather than a flat 0/1, because the
     * two paths must not disagree: the staff console and the lobby display read
     * through SQL, the client board and the unit tests read through PHP. A binary
     * expression here against a weighted one there would show two different
     * queues for the same people, which is worse than either being wrong alone.
     *
     * MIN() over the categories a client actually holds, mirroring how
     * priorityRank() takes the best weight a client qualifies for.
     *
     * Weights are cast from config, not interpolated from the database, so they
     * cannot carry a quote into the statement.
     */
    private function priorityOrderSql(): string
    {
        $weights = $this->priorityWeights();
        $worst = max($weights) + 1;

        $verified = 'priority_verified_at IS NOT NULL AND (';

        $clauses = [];

        foreach ($weights as $column => $weight) {
            $clauses[] = "claims_{$column} = 1";
        }

        // Deliberate: the claim column names come from priorityWeights()'s
        // fixed key set, not from user input.
        $verified .= implode(' OR ', $clauses).')';

        return "CASE WHEN {$verified} THEN LEAST({$worst}, ".$this->bestWeightSql().") ELSE {$worst} END";
    }

    /** SQL expression for the best (lowest) weight a client's claims qualify for. */
    private function bestWeightSql(): string
    {
        $weights = $this->priorityWeights();

        $parts = [];

        foreach ($weights as $column => $weight) {
            $parts[] = "WHEN claims_{$column} = 1 THEN {$weight}";
        }

        return 'CASE '.implode(' ', $parts).' END';
    }

    /**
     * COALESCE across the three arrival sources, all of which live on the
     * appointments table itself.
     *
     * The middle term was `scheduled_at` here and `time_slots.start_at` in
     * arrivalTimestamp(). Those agree for an online booking and disagree for a
     * walk-in, which has scheduled_at = null by definition - so a walk-in fell
     * through to created_at in SQL but used the slot's start time in PHP, and the
     * console could show one order where the client board showed another.
     *
     * Dropping scheduled_at entirely makes the two definitions identical: the
     * only remaining differences are `checked_in_at` (which is the same column
     * in both) and `created_at` (also the same). A walk-in, which checks in
     * immediately, has checked_in_at set - so the fallback is not reached at all.
     *
     * Joining time_slots to supply start_at would have cost a join on the hottest
     * query in the application to fix a case the coalesce already handles.
     */
    private function arrivalOrderSql(): string
    {
        return 'COALESCE(checked_in_at, created_at)';
    }
}
