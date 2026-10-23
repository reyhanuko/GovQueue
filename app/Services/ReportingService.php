<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PriorityType;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analytics over the operational record.
 *
 * Three rules shape everything here, each learned the hard way:
 *
 *  1. **A metric with no data is null, never zero.** "Average wait: 0 minutes"
 *     reads as "we served everyone instantly". "Not enough data" is the truth.
 *     Every average therefore returns a value *and* the sample size behind it.
 *
 *  2. **Every rate carries its denominator.** A no-show rate needs to know it was
 *     computed from four tickets, not four thousand, or it implies a precision
 *     that does not exist.
 *
 *  3. **One date key per question.** Bookings are counted by created_at,
 *     completed work by completed_at, and arrivals by checked_in_at. Grouping a
 *     single report by the wrong one of these double counts.
 *
 * Arithmetic is pushed into SQL wherever MySQL can do it exactly - notably
 * TIMESTAMPDIFF for waits and service times - so a year of data does not have to
 * be pulled into PHP to be measured.
 */
class ReportingService
{
    /**
     * Below this many observations a rate or an average is presented as
     * indicative rather than factual. Mirrors the rule already used by
     * QueueService::averageServiceMinutes().
     */
    public const RELIABLE_SAMPLE = 5;

    /* ------------------------------------------------------------------ */
    /* Headline summary */
    /* ------------------------------------------------------------------ */

    /**
     * The numbers an administrator opens the reports screen for.
     *
     * One population - "appointments in this period", keyed on queue_date - and
     * every outcome is a subset of it, so the rates share a denominator that
     * means something. "Bookings made" is reported alongside it and clearly
     * labelled, because booking a slot for next month is a different event from
     * working a ticket today, and conflating them is what makes a report useless.
     *
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        // The population: every ticket that belonged to these days.
        $inPeriod = $this->queueDateQuery($from, $to);
        $population = (clone $inPeriod)->count();

        // Only tickets from this population that reached a terminal state.
        $decided = (clone $inPeriod)
            ->whereIn('status', [
                AppointmentStatus::Completed->value,
                AppointmentStatus::Cancelled->value,
                AppointmentStatus::NoShow->value,
            ]);

        $completed = (clone $inPeriod)->where('status', AppointmentStatus::Completed->value)->count();
        $cancelled = (clone $inPeriod)->where('status', AppointmentStatus::Cancelled->value)->count();
        $noShow = (clone $inPeriod)->where('status', AppointmentStatus::NoShow->value)->count();
        $decidedTotal = (clone $decided)->count();

        // Skips are a quality signal, not an outcome: a skipped ticket can still
        // go on to be served, so this is never part of an outcome rate.
        $skipped = (clone $inPeriod)->where('skip_count', '>', 0)->count();

        // Waits and service times come from the same population but only from
        // tickets that were actually served - a cancelled ticket waited nowhere.
        $served = (clone $inPeriod)
            ->where('status', AppointmentStatus::Completed->value)
            ->whereNotNull('serving_started_at');

        $waits = $this->waitStatistics($served);
        $services = $this->serviceTimeStatistics($served);
        $accuracy = $this->estimateAccuracy($served);

        return [
            // Bookings *made*, i.e. when the client clicked confirm.
            'bookings_made' => (clone $this->bookingsQuery($from, $to))->count(),
            // Tickets that belonged to these days.
            'in_period' => $population,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'no_show' => $noShow,
            'skipped' => $skipped,

            /*
             * The remainder, so the figures add up.
             *
             * Every ticket in the population is either decided (completed,
             * cancelled, no-show) or not yet decided (pending, confirmed,
             * checked in, being served). The screen used to show the population
             * and the decided outcomes side by side with nothing in between,
             * which read as an arithmetic failure: "216 appointments, 181
             * completed, 27 no-show, 0 cancelled" leaves 8 unaccounted and no
             * way to tell whether they were a bug or simply still open.
             *
             * It is not a new query - it is the population minus the decided
             * total, which is exactly the arithmetic a reader would do by hand.
             */
            'still_open' => max(0, $population - $decidedTotal),

            // Every rate shows the population it was taken from.
            'cancellation_rate' => $this->rate($cancelled, $decidedTotal),
            'cancellation_base' => $decidedTotal,
            'no_show_rate' => $this->rate($noShow, $decidedTotal),
            'no_show_base' => $decidedTotal,
            'arrivals' => $this->arrivals($from, $to),

            'avg_wait_minutes' => $waits['average'],
            'wait_samples' => $waits['samples'],
            'longest_wait_minutes' => $waits['maximum'],
            'wait_is_reliable' => $waits['samples'] >= self::RELIABLE_SAMPLE,

            'avg_service_minutes' => $services['average'],
            'service_samples' => $services['samples'],
            'service_is_reliable' => $services['samples'] >= self::RELIABLE_SAMPLE,

            'estimate' => $accuracy,
        ];
    }

    /**
     * How good is the wait estimate the client was actually given?
     *
     * Only comparable for tickets that were served, and only where an estimate
     * was recorded at check-in. Tickets from before that was stored simply do
     * not contribute rather than being counted as a wild miss.
     *
     * @return array{average_error: ?float, average_estimate: ?float, average_actual: ?float, samples: int, over_promised: int, under_promised: int}
     */
    public function estimateAccuracy($finishedQuery): array
    {
        $rows = (clone $finishedQuery)
            ->whereNotNull('checked_in_at')
            ->whereNotNull('serving_started_at')
            ->whereNotNull('estimated_wait_minutes')
            ->get([
                'estimated_wait_minutes',
                'checked_in_at',
                'serving_started_at',
            ]);

        if ($rows->isEmpty()) {
            return [
                'average_error' => null,
                'average_estimate' => null,
                'average_actual' => null,
                'samples' => 0,
                'over_promised' => 0,
                'under_promised' => 0,
            ];
        }

        $estimateTotal = 0;
        $actualTotal = 0;
        $errorTotal = 0;
        $over = 0;
        $under = 0;

        foreach ($rows as $row) {
            $estimate = (float) $row->estimated_wait_minutes;
            $actual = (float) $this->minutesBetween($row->checked_in_at, $row->serving_started_at);

            $estimateTotal += $estimate;
            $actualTotal += $actual;
            $errorTotal += abs($estimate - $actual);

            if ($estimate > $actual) {
                $over++;
            } elseif ($estimate < $actual) {
                $under++;
            }
        }

        $count = $rows->count();

        return [
            'average_error' => round($errorTotal / $count, 1),
            'average_estimate' => round($estimateTotal / $count, 1),
            'average_actual' => round($actualTotal / $count, 1),
            'samples' => $count,
            'over_promised' => $over,
            'under_promised' => $under,
        ];
    }

    /**
     * Wait statistics measured with MySQL's own TIMESTAMPDIFF.
     *
     * @return array{average: ?float, samples: int, maximum: ?float}
     */
    private function waitStatistics($finishedQuery): array
    {
        $row = (clone $finishedQuery)
            ->whereNotNull('checked_in_at')
            ->whereNotNull('serving_started_at')
            ->selectRaw('COUNT(*) as samples')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, checked_in_at, serving_started_at)) as average')
            ->selectRaw('MAX(TIMESTAMPDIFF(MINUTE, checked_in_at, serving_started_at)) as maximum')
            ->first();

        $samples = (int) ($row->samples ?? 0);

        if ($samples === 0) {
            return ['average' => null, 'samples' => 0, 'maximum' => null];
        }

        return [
            'average' => round((float) $row->average, 1),
            'samples' => $samples,
            'maximum' => (int) $row->maximum,
        ];
    }

    /**
     * Time actually spent at the counter, compared with the service's own
     * configured duration so badly-calibrated services become visible.
     *
     * @return array{average: ?float, samples: int, maximum: ?float}
     */
    private function serviceTimeStatistics($finishedQuery): array
    {
        $row = (clone $finishedQuery)
            ->whereNotNull('serving_started_at')
            ->whereNotNull('completed_at')
            ->selectRaw('COUNT(*) as samples')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, serving_started_at, completed_at)) as average')
            ->selectRaw('MAX(TIMESTAMPDIFF(MINUTE, serving_started_at, completed_at)) as maximum')
            ->first();

        $samples = (int) ($row->samples ?? 0);

        if ($samples === 0) {
            return ['average' => null, 'samples' => 0, 'maximum' => null];
        }

        return [
            'average' => round((float) $row->average, 1),
            'samples' => $samples,
            'maximum' => (int) $row->maximum,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Where the pressure is */
    /* ------------------------------------------------------------------ */

    /**
     * Demand against the capacity actually offered, per service.
     *
     * Both sides of this comparison are keyed on the *day of service*, never on
     * when the booking was made. That matters: pairing "bookings made this month"
     * with "capacity on this month's calendar" compares two unrelated sets of
     * days, and produces a utilisation figure that reads as a crisis or a
     * landslide depending only on which way the dates happen to fall.
     *
     * The number that matters for planning is not "how many booked" but whether
     * the office ran out: `filled_slots` counts slots that were genuinely full,
     * and `turn_away_risk` flags services where demand meets the ceiling.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function servicePerformance(Carbon $from, Carbon $to): Collection
    {
        // Demand: tickets expected to be served during the period.
        $demandRows = $this->queueDateQuery($from, $to)
            ->selectRaw('service_id, COUNT(*) as booked')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->selectRaw("SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show")
            ->selectRaw('SUM(CASE WHEN skip_count > 0 THEN 1 ELSE 0 END) as skipped')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, checked_in_at, serving_started_at)) as avg_wait')
            ->selectRaw('COUNT(checked_in_at) as wait_samples')
            ->groupBy('service_id')
            ->get()
            ->keyBy('service_id');

        // Supply: the slots on the calendar for those same days.
        $capacityRows = TimeSlot::query()
            ->whereDate('slot_date', '>=', $from->toDateString())
            ->whereDate('slot_date', '<=', $to->toDateString())
            ->selectRaw('service_id')
            ->selectRaw('SUM(capacity) as capacity')
            ->selectRaw('COUNT(*) as slot_count')
            ->groupBy('service_id')
            ->get()
            ->keyBy('service_id');

        /*
         * Slots that filled up at some point.
         *
         * Deliberately NOT booked_count: that is *current* occupancy, and a slot
         * releases its place the moment an appointment completes, so every slot
         * in a past window reads as empty and a historical "did we run out?"
         * question always answers no. Counting every appointment ever attached
         * to the slot - including the completed ones - measures whether demand
         * actually reached the ceiling.
         */
        $fill = TimeSlot::query()
            ->whereDate('slot_date', '>=', $from->toDateString())
            ->whereDate('slot_date', '<=', $to->toDateString())
            ->selectRaw('time_slots.service_id')
            ->selectRaw('COUNT(*) as slot_count')
            ->selectRaw('SUM(CASE WHEN demand.total >= time_slots.capacity THEN 1 ELSE 0 END) as full_slots')
            ->leftJoinSub(
                Appointment::query()
                    ->whereNotNull('time_slot_id')
                    ->selectRaw('time_slot_id, COUNT(*) as total')
                    ->groupBy('time_slot_id'),
                'demand',
                'demand.time_slot_id',
                '=',
                'time_slots.id'
            )
            ->groupBy('time_slots.service_id')
            ->get()
            ->keyBy('service_id');

        return Service::with('window')
            ->orderBy('name')
            ->get()
            ->map(function (Service $service) use ($demandRows, $capacityRows, $fill) {
                $demand = $demandRows->get($service->id);
                $capacity = $capacityRows->get($service->id);
                $fillRow = $fill->get($service->id);

                $capacityTotal = (int) ($capacity->capacity ?? 0);
                $booked = (int) ($demand->booked ?? 0);
                $filled = (int) ($fillRow->full_slots ?? 0);
                $slotCount = (int) ($fillRow->slot_count ?? 0);
                $waitSamples = (int) ($demand->wait_samples ?? 0);

                return [
                    'service' => $service,
                    'booked' => $booked,
                    'completed' => (int) ($demand->completed ?? 0),
                    'cancelled' => (int) ($demand->cancelled ?? 0),
                    'no_show' => (int) ($demand->no_show ?? 0),
                    'skipped' => (int) ($demand->skipped ?? 0),
                    'avg_wait' => $waitSamples > 0 ? round((float) $demand->avg_wait, 1) : null,
                    'wait_samples' => $waitSamples,
                    'wait_is_reliable' => $waitSamples >= self::RELIABLE_SAMPLE,
                    'capacity' => $capacityTotal,
                    'slot_count' => $slotCount,
                    'filled_slots' => $filled,
                    'offered_too_little' => $capacityTotal === 0,
                    // Demand over supply on the same days. Dividing by zero would
                    // give INF or a PHP error, and a service with no slots on the
                    // calendar has no meaningful rate at all.
                    'utilisation' => $capacityTotal > 0
                        ? round($booked / $capacityTotal * 100, 1)
                        : null,
                    'turn_away_risk' => $slotCount > 0
                        && round($filled / $slotCount * 100) >= 80,
                ];
            });
    }

    /**
     * The same pressure view per counter, for balancing staffing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function windowPerformance(Carbon $from, Carbon $to): Collection
    {
        $rows = $this->queueDateQuery($from, $to)
            ->selectRaw('service_window_id')
            ->selectRaw('COUNT(*) as booked')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, checked_in_at, serving_started_at)) as avg_wait')
            ->selectRaw('COUNT(checked_in_at) as wait_samples')
            ->groupBy('service_window_id')
            ->get()
            ->keyBy('service_window_id');

        // transactions has no window column of its own - it records service_id,
        // which is joined through services. Joining on the service rather than on
        // appointments means a transaction captured without an appointment still
        // lands on the right counter.
        $fees = Transaction::query()
            ->join('services', 'services.id', '=', 'transactions.service_id')
            ->whereBetween('transactions.completed_at', [
                $from->copy()->startOfDay(),
                $to->copy()->endOfDay(),
            ])
            ->selectRaw('services.service_window_id as window_id')
            ->selectRaw('SUM(transactions.fee_paid) as collected')
            ->groupBy('services.service_window_id')
            ->get()
            ->keyBy('window_id');

        return ServiceWindow::orderBy('name')->get()->map(function (ServiceWindow $window) use ($rows, $fees) {
            $row = $rows->get($window->id);
            $feeRow = $fees->get($window->id);
            $samples = (int) ($row->wait_samples ?? 0);
            $booked = (int) ($row->booked ?? 0);

            return [
                'window' => $window,
                'booked' => $booked,
                'completed' => (int) ($row->completed ?? 0),
                'cancelled' => (int) ($row->cancelled ?? 0),
                'no_show' => (int) ($row->no_show ?? 0),
                'avg_wait' => $samples > 0 ? round((float) $row->avg_wait, 1) : null,
                'wait_samples' => $samples,
                'wait_is_reliable' => $samples >= self::RELIABLE_SAMPLE,
                'no_show_rate' => $this->rate((int) ($row->no_show ?? 0), $booked),
                'completion_rate' => $this->rate((int) ($row->completed ?? 0), $booked),
                'fees_collected' => (float) ($feeRow->collected ?? 0),
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /* Shape of the day */
    /* ------------------------------------------------------------------ */

    /**
     * Arrivals by hour of day, so staffing can be matched to demand.
     *
     * Buckets are built in PHP and left-filled, because a quiet hour with no
     * arrivals is a real data point - "nobody came at 15:00" - and dropping the
     * empty bucket would make the chart lie by omission.
     *
     * @return array<int, array{hour: int, label: string, arrivals: int, completed: int, wait_average: ?float, wait_samples: int}>
     */
    public function peakHours(Carbon $from, Carbon $to): array
    {
        $arrivals = $this->arrivalsQuery($from, $to)
            ->whereNotNull('checked_in_at')
            ->selectRaw('HOUR(checked_in_at) as hour')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, checked_in_at, serving_started_at)) as avg_wait')
            ->selectRaw('COUNT(serving_started_at) as wait_samples')
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        $completions = $this->finishedQuery($from, $to)
            ->whereNotNull('completed_at')
            ->selectRaw('HOUR(completed_at) as hour')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        $hours = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $arrivalRow = $arrivals->get($hour);
            $samples = (int) ($arrivalRow->wait_samples ?? 0);

            $hours[$hour] = [
                'hour' => $hour,
                'label' => Carbon::createFromTime($hour, 0)->format('g A'),
                'arrivals' => (int) ($arrivalRow->total ?? 0),
                'completed' => (int) ($completions->get($hour)->total ?? 0),
                'wait_average' => $samples > 0 ? round((float) $arrivalRow->avg_wait, 1) : null,
                'wait_samples' => $samples,
            ];
        }

        return $hours;
    }

    /**
     * Bookings and completions per day, for the trend chart.
     *
     * @return array<int, array{date: string, label: string, booked: int, completed: int}>
     */
    public function dailyTrend(Carbon $from, Carbon $to): array
    {
        $booked = $this->queueDateQuery($from, $to)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $completed = $this->finishedQuery($from, $to)
            ->selectRaw('DATE(completed_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = [];
        $cursor = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        while ($cursor->lte($last)) {
            $day = $cursor->toDateString();

            $series[] = [
                'date' => $day,
                'label' => $cursor->format('j M'),
                'booked' => (int) ($booked->get($day)->total ?? 0),
                'completed' => (int) ($completed->get($day)->total ?? 0),
            ];

            $cursor->addDay();
        }

        return $series;
    }

    /* ------------------------------------------------------------------ */
    /* Statutory priority handling */
    /* ------------------------------------------------------------------ */

    /**
     * Priority claims against what staff actually decided.
     *
     * This is the compliance view for RA 9994 and RA 10754: who claimed each
     * category, how many were approved, and how many were refused.
     *
     * Refusals are read from the audit trail rather than from appointments,
     * because QueueService::refusePriority() deliberately clears all three claim
     * flags. That is right for queue order - a refused claim must stop granting
     * precedence immediately - but it means a refused claim is indistinguishable
     * from never having been made. The audit log is the only place the decision,
     * the reason, and the staff member survive, so it is the only honest source.
     *
     * @return array<string, array<string, mixed>>
     */
    public function priorityHandling(Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        // Approved: the appointment still carries its verification timestamp.
        $approvedByType = [];

        foreach ([
            PriorityType::SeniorCitizen->value => 'claims_senior_citizen',
            PriorityType::Pwd->value => 'claims_pwd',
            PriorityType::Pregnant->value => 'claims_pregnant',
        ] as $type => $column) {
            $approvedByType[$type] = $this->queueDateQuery($from, $to)
                ->where($column, true)
                ->whereNotNull('priority_verified_at')
                ->count();
        }

        // Refused: the audit entry names the categories it voided. A ticket can
        // carry more than one claim, so the payload's list is split per category.
        $refusedEntries = AuditLog::query()
            ->where('action', 'appointment.priority_refused')
            ->whereBetween('created_at', [$start, $end])
            ->get(['payload', 'created_at']);

        $refusedByType = array_fill_keys(array_keys($approvedByType), 0);
        $refusalReasons = [];

        foreach ($refusedEntries as $entry) {
            // payload['refused'] is a comma-joined list of human labels, because
            // that is what staff read on the audit screen.
            $labels = array_map('trim', explode(',', (string) ($entry->payload['refused'] ?? '')));

            foreach ($refusedByType as $type => $_) {
                if (in_array(PriorityType::from($type)->label(), $labels, true)) {
                    $refusedByType[$type]++;
                }
            }

            $reason = trim((string) ($entry->payload['reason'] ?? ''));

            if ($reason !== '') {
                $refusalReasons[$reason] = ($refusalReasons[$reason] ?? 0) + 1;
            }
        }

        arsort($refusalReasons);

        $rows = [];

        foreach ([
            PriorityType::SeniorCitizen->value => 'claims_senior_citizen',
            PriorityType::Pwd->value => 'claims_pwd',
            PriorityType::Pregnant->value => 'claims_pregnant',
        ] as $type => $column) {
            $stillClaimed = $this->queueDateQuery($from, $to)->where($column, true)->count();

            // Claims still standing plus the ones staff voided: the original
            // number of people who asked, before the decision was applied.
            $claimed = $stillClaimed + $refusedByType[$type];
            $approved = $approvedByType[$type];
            $refused = $refusedByType[$type];
            $decided = $approved + $refused;

            $rows[$type] = [
                'label' => PriorityType::from($type)->label(),
                'basis' => PriorityType::from($type)->basis(),
                'claimed' => $claimed,
                'approved' => $approved,
                'refused' => $refused,
                'awaiting_decision' => max(0, $stillClaimed - $approved),
                'approval_rate' => $decided > 0 ? $this->rate($approved, $decided) : null,
                'decided' => $decided,
            ];
        }

        return ['categories' => $rows, 'refusal_reasons' => $refusalReasons];
    }

    /**
     * Why clients cancelled, most common first.
     *
     * Whitespace-only reasons are dropped alongside nulls: a stray space typed into
     * the form is not a reason, and grouping by it would put a meaningless row at
     * the top of the list.
     *
     * @return Collection<int, array{reason: string, total: int}>
     */
    public function cancellationReasons(Carbon $from, $to): Collection
    {
        return $this->queueDateQuery($from, $to)
            ->where('status', AppointmentStatus::Cancelled->value)
            ->whereNotNull('cancel_reason')
            ->whereRaw("TRIM(cancel_reason) <> ''")
            ->selectRaw('TRIM(cancel_reason) as reason')
            ->selectRaw('COUNT(*) as total')
            ->groupBy(DB::raw('TRIM(cancel_reason)'))
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'reason' => $row->reason,
                'total' => (int) $row->total,
            ]);
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    /**
     * Tickets *booked* in the period. Keyed on created_at, which is the only date
     * every ticket has regardless of whether it has a slot or a schedule.
     *
     * queue_date was deliberately not used: it is the day the ticket is expected
     * to be served, so a booking made on the 28th for the 3rd belongs to neither
     * period cleanly and would be double counted across two reports.
     */
    private function bookingsQuery(Carbon $from, Carbon $to)
    {
        return Appointment::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /**
     * Tickets *expected to be served* during the period.
     *
     * queue_date is the day a ticket belongs to, and it is set on every ticket
     * including walk-ins - which scheduled_at is not. Anything compared against a
     * slot calendar has to use this, so both sides describe the same days.
     */
    private function queueDateQuery(Carbon $from, Carbon $to)
    {
        return Appointment::query()
            ->whereBetween('queue_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /**
     * Tickets *completed* in the period.
     */
    private function finishedQuery(Carbon $from, Carbon $to)
    {
        return Appointment::query()
            ->where('status', AppointmentStatus::Completed->value)
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /**
     * Tickets that physically arrived at the office.
     */
    private function arrivalsQuery(Carbon $from, Carbon $to)
    {
        return Appointment::query()
            ->whereBetween('checked_in_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    private function arrivals(Carbon $from, Carbon $to): int
    {
        return $this->arrivalsQuery($from, $to)->count();
    }

    /**
     * A ratio, or null when the denominator is zero.
     *
     * Null rather than 0 because "0%" and "no tickets to measure" are different
     * statements, and only one of them is good news.
     */
    private function rate(int $part, int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part / $whole * 100, 1);
    }

    private function minutesBetween($from, $to): float
    {
        return (float) $from->diffInMinutes($to);
    }

    /**
     * Resolve a reporting period from the request, defaulting to the last 30 days.
     *
     * @return array{0: Carbon, 1: Carbon, 2: int}
     */
    public function periodFrom(?string $preset, ?string $from, ?string $to): array
    {
        $today = today();

        if ($from && $to) {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->startOfDay();

            if ($start->gt($end)) {
                // Swapped inputs are a typo, not a reason to render an empty report.
                [$start, $end] = [$end, $start];
            }

            // Carbon 3 returns a float from diffInDays, so an integer cast is
            // needed or the caller gets 30.0 where it expects an int.
            return [$start, $end, (int) $start->diffInDays($end) + 1];
        }

        $days = match ($preset) {
            '7' => 7,
            '90' => 90,
            '180' => 180,
            default => 30,
        };

        return [$today->copy()->subDays($days - 1)->startOfDay(), $today->copy()->startOfDay(), $days];
    }

    /**
     * The range of dates a report can meaningfully cover.
     *
     * Used to bound the date inputs. They were previously unbounded, so a
     * mistyped year - 02026, or 2026 when the data is from 2025 - was accepted
     * silently and produced an empty report. An empty report and a report with
     * no data look identical, so an administrator could spend a long time
     * believing the queue had stopped working.
     *
     * The floor is the earliest booking on record rather than a fixed date, so
     * it stays correct on a fresh install with no history at all.
     *
     * @return array{0: string, 1: string} earliest and latest selectable date
     */
    public function dateBounds(): array
    {
        $earliest = Appointment::query()->min('queue_date');

        return [
            $earliest ? Carbon::parse($earliest)->toDateString() : today()->subYear()->toDateString(),
            today()->toDateString(),
        ];
    }

    /**
     * The presets offered by the period picker.
     *
     * @return array<string, string>
     */
    public function presets(): array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            '180' => 'Last 6 months',
        ];
    }
}
