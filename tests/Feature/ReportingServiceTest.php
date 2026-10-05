<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use App\Services\QueueService;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reporting arithmetic.
 *
 * Three rules are under test here, because each one produced a wrong number
 * before it was fixed:
 *
 *  1. **No data is null, never zero.** An average wait of "0 minutes" reads as
 *     "we served everyone instantly". Every average carries its sample size, and
 *     a rate with no denominator is null rather than 0%.
 *  2. **One date key per question.** Demand and capacity have to describe the
 *     same days or the ratio between them means nothing.
 *  3. **Occupancy is not demand.** booked_count is current occupancy and empties
 *     as clients are served, so it cannot answer "did we run out?".
 */
class ReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportingService $reports;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = new ReportingService;

        $this->window = ServiceWindow::factory()->create([
            'code' => 'WINDOW_1',
            'avg_service_minutes' => 15,
        ]);

        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'RPT-TEST',
        ]);
    }

    private function period(): array
    {
        return [today()->subDays(29)->startOfDay(), today()->startOfDay()];
    }

    /**
     * A slot on a given day relative to today.
     *
     * Positive $daysAgo looks backwards, which suits historical reporting tests.
     */
    private function slot(int $daysAgo, string $start, int $capacity = 5): TimeSlot
    {
        $date = today()->subDays($daysAgo);

        return $this->makeSlot($date->toDateString(), $start, $capacity);
    }

    /** A slot in the future, so it is genuinely bookable. */
    private function futureSlot(int $daysAhead = 1, string $start = '09:00:00', int $capacity = 5): TimeSlot
    {
        return $this->makeSlot(today()->addDays($daysAhead)->toDateString(), $start, $capacity);
    }

    private function makeSlot(string $date, string $start, int $capacity = 5): TimeSlot
    {
        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $date,
            'start_time' => $start,
            'end_time' => date('H:i:s', strtotime($start) + 1800),
            'start_at' => $date.' '.$start,
            'end_at' => date('Y-m-d H:i:s', strtotime($date.' '.$start) + 1800),
            'capacity' => $capacity,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Empty period */
    /* ------------------------------------------------------------------ */

    public function test_an_empty_period_reports_nothing_rather_than_zero(): void
    {
        [$from, $to] = $this->period();

        $summary = $this->reports->summary($from, $to);

        $this->assertSame(0, $summary['in_period']);
        $this->assertSame(0, $summary['completed']);

        // These are the figures that must be null, not 0.
        $this->assertNull($summary['avg_wait_minutes'], 'An average over no rows must be null.');
        $this->assertNull($summary['avg_service_minutes']);
        $this->assertNull($summary['longest_wait_minutes']);
        $this->assertNull($summary['cancellation_rate'], 'A rate with no denominator must be null.');
        $this->assertNull($summary['no_show_rate']);

        $this->assertSame(0, $summary['wait_samples']);
        $this->assertFalse($summary['wait_is_reliable']);
        $this->assertSame(0, $summary['estimate']['samples']);
        $this->assertNull($summary['estimate']['average_error']);
    }

    public function test_a_service_with_no_slots_reports_no_utilisation_rather_than_dividing_by_zero(): void
    {
        [$from, $to] = $this->period();

        $row = $this->reports->servicePerformance($from, $to)
            ->firstWhere('service.id', $this->service->id);

        $this->assertNotNull($row);
        $this->assertNull($row['utilisation'], 'Zero capacity must not produce a percentage.');
        $this->assertTrue($row['offered_too_little']);
        $this->assertSame(0, $row['capacity']);
        $this->assertFalse($row['turn_away_risk']);
        $this->assertNull($row['avg_wait']);
    }

    /* ------------------------------------------------------------------ */
    /* Measuring waits */
    /* ------------------------------------------------------------------ */

    public function test_average_wait_is_measured_from_check_in_to_being_served(): void
    {
        [$from, $to] = $this->period();

        foreach ([10, 20, 30] as $wait) {
            $slot = $this->slot(1, sprintf('%02d:%02d:00', 8 + intdiv($wait, 30), $wait % 30));

            Appointment::factory()->create([
                'service_id' => $this->service->id,
                'service_window_id' => $this->window->id,
                'time_slot_id' => $slot->id,
                'status' => 'completed',
                'queue_date' => today()->subDay()->toDateString(),
                'created_at' => now()->subDay(),
                'checked_in_at' => now()->subMinutes($wait),
                'serving_started_at' => now(),
                'completed_at' => now(),
            ]);
        }

        $summary = $this->reports->summary($from, $to);

        $this->assertSame(20.0, $summary['avg_wait_minutes']);
        $this->assertSame(3, $summary['wait_samples']);
        $this->assertSame(30, $summary['longest_wait_minutes']);
    }

    public function test_a_cancelled_ticket_does_not_contribute_a_wait(): void
    {
        [$from, $to] = $this->period();

        // Checked in, then cancelled: it waited for nothing.
        Appointment::factory()->cancelled()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'queue_date' => today()->toDateString(),
            'created_at' => now(),
            'checked_in_at' => now()->subHours(4),
            'serving_started_at' => null,
        ]);

        $summary = $this->reports->summary($from, $to);

        $this->assertSame(0, $summary['wait_samples']);
        $this->assertNull($summary['avg_wait_minutes']);
    }

    public function test_waits_are_attributed_to_the_day_of_service_not_the_day_of_booking(): void
    {
        [$from, $to] = $this->period();

        // Booked ten days ago for today.
        $slot = $this->slot(0, '09:00:00');

        Appointment::factory()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'status' => 'completed',
            'queue_date' => today()->toDateString(),
            'created_at' => now()->subDays(10),
            'checked_in_at' => now()->subMinutes(25),
            'serving_started_at' => now(),
            'completed_at' => now(),
        ]);

        $summary = $this->reports->summary($from, $to);

        $this->assertSame(1, $summary['in_period'], 'Belongs to today.');
        $this->assertSame(1, $summary['bookings_made'], 'Was made ten days ago.');
    }

    /* ------------------------------------------------------------------ */
    /* Estimate accuracy */
    /* ------------------------------------------------------------------ */

    public function test_estimate_accuracy_is_not_reported_without_a_stored_estimate(): void
    {
        [$from, $to] = $this->period();

        $slot = $this->slot(0, '09:00:00');

        // No estimated_wait_minutes, so there is nothing to compare against.
        Appointment::factory()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'status' => 'completed',
            'queue_date' => today()->toDateString(),
            'created_at' => now(),
            'checked_in_at' => now()->subMinutes(40),
            'serving_started_at' => now(),
            'completed_at' => now(),
            'estimated_wait_minutes' => null,
        ]);

        $summary = $this->reports->summary($from, $to);

        $this->assertSame(0, $summary['estimate']['samples']);
        $this->assertNull($summary['estimate']['average_error']);
    }

    public function test_estimate_accuracy_reports_the_size_of_the_miss_and_its_direction(): void
    {
        [$from, $to] = $this->period();

        // Promised 10, waited 20 -> we under-promised by 10.
        // Promised 30, waited 10 -> we over-promised by 20.
        foreach ([[10, 20], [30, 10]] as [$estimate, $actual]) {
            $slot = $this->slot(0, sprintf('%02d:%02d:00', 8 + intdiv($estimate, 30), $estimate % 30));

            Appointment::factory()->create([
                'service_id' => $this->service->id,
                'service_window_id' => $this->window->id,
                'time_slot_id' => $slot->id,
                'status' => 'completed',
                'queue_date' => today()->toDateString(),
                'created_at' => now(),
                'checked_in_at' => now()->subMinutes($actual),
                'serving_started_at' => now(),
                'completed_at' => now(),
                'estimated_wait_minutes' => $estimate,
            ]);
        }

        $estimate = $this->reports->summary($from, $to)['estimate'];

        $this->assertSame(2, $estimate['samples']);
        $this->assertSame(15.0, $estimate['average_error'], '(10 + 20) / 2');
        $this->assertSame(20.0, $estimate['average_estimate']);
        $this->assertSame(15.0, $estimate['average_actual']);
        $this->assertSame(1, $estimate['under_promised']);
        $this->assertSame(1, $estimate['over_promised']);
    }

    public function test_an_estimate_of_zero_is_a_real_measurement_not_missing_data(): void
    {
        [$from, $to] = $this->period();

        // Front of the queue is genuinely told zero. Treating that as "no data"
        // would drop every accurately-predicted immediate service.
        $slot = $this->slot(0, '09:00:00');

        Appointment::factory()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'status' => 'completed',
            'queue_date' => today()->toDateString(),
            'created_at' => now(),
            'checked_in_at' => now()->subMinutes(2),
            'serving_started_at' => now(),
            'completed_at' => now(),
            'estimated_wait_minutes' => 0,
        ]);

        $estimate = $this->reports->summary($from, $to)['estimate'];

        $this->assertSame(1, $estimate['samples']);
        $this->assertSame(2.0, $estimate['average_error']);
    }

    /* ------------------------------------------------------------------ */
    /* Demand against capacity */
    /* ------------------------------------------------------------------ */

    public function test_utilisation_compares_appointments_against_places_on_the_same_days(): void
    {
        [$from, $to] = $this->period();

        // One day offering 10 + 5 + 5 = 20 places, and 2 appointments for that
        // same day -> 10%.
        $this->slot(0, '09:00:00', capacity: 10);

        foreach (range(1, 2) as $i) {
            $slot = $this->slot(0, sprintf('1%d:00:00', $i), capacity: 5);

            Appointment::factory()->create([
                'service_id' => $this->service->id,
                'service_window_id' => $this->window->id,
                'time_slot_id' => $slot->id,
                'status' => 'cancelled',
                'queue_date' => today()->toDateString(),
                'created_at' => now(),
            ]);
        }

        $row = $this->reports->servicePerformance($from, $to)
            ->firstWhere('service.id', $this->service->id);

        $this->assertSame(20, $row['capacity']);
        $this->assertSame(2, $row['booked']);
        $this->assertSame(10.0, $row['utilisation']);
    }

    public function test_slots_filled_is_not_the_same_as_current_occupancy(): void
    {
        [$from, $to] = $this->period();

        // A two-place slot that three clients once held. All are now completed, so
        // booked_count is 0 again - but the office did turn people away.
        $slot = $this->slot(0, '09:00:00', capacity: 2);

        for ($i = 0; $i < 3; $i++) {
            Appointment::factory()->create([
                'service_id' => $this->service->id,
                'service_window_id' => $this->window->id,
                'time_slot_id' => $slot->id,
                'status' => 'completed',
                'queue_date' => today()->toDateString(),
                'created_at' => now(),
                'checked_in_at' => now()->subMinutes(20),
                'serving_started_at' => now()->subMinutes(10),
                'completed_at' => now(),
            ]);
        }

        $this->assertSame(0, $slot->fresh()->booked_count, 'Slot is empty now that they are served.');

        $row = $this->reports->servicePerformance($from, $to)
            ->firstWhere('service.id', $this->service->id);

        $this->assertSame(1, $row['slot_count']);
        $this->assertSame(1, $row['filled_slots'], 'The slot did reach its ceiling at some point.');
        $this->assertTrue($row['turn_away_risk']);
    }

    /* ------------------------------------------------------------------ */
    /* Priority compliance */
    /* ------------------------------------------------------------------ */

    public function test_priority_report_counts_refusals_from_the_audit_trail(): void
    {
        [$from, $to] = $this->period();

        $service = $this->service;

        // Two approved senior claims.
        for ($i = 0; $i < 2; $i++) {
            Appointment::factory()->verifiedPriority(senior: true)->create([
                'service_id' => $service->id,
                'service_window_id' => $this->window->id,
                'queue_date' => today()->toDateString(),
                'created_at' => now(),
            ]);
        }

        // One refused. refusePriority() clears the claim flags, so the appointment
        // row itself can no longer show that a claim was ever made.
        $refused = Appointment::factory()->unverifiedPriority(senior: true)->create([
            'service_id' => $service->id,
            'service_window_id' => $this->window->id,
            'queue_date' => today()->toDateString(),
            'created_at' => now(),
        ]);

        app(QueueService::class)->refusePriority(
            $refused,
            User::factory()->staff()->create(['service_window_id' => $this->window->id]),
            'No valid ID presented',
        );

        $senior = $this->reports->priorityHandling($from, $to)['categories']['senior_citizen'];

        $this->assertSame(2, $senior['approved']);
        $this->assertSame(1, $senior['refused'], 'The refusal must survive being erased from the ticket.');
        $this->assertSame(3, $senior['claimed'], 'Still-claimed plus refused.');
        $this->assertSame(3, $senior['decided']);
        $this->assertSame(66.7, $senior['approval_rate']);
    }

    public function test_the_priority_report_lists_the_reasons_staff_gave(): void
    {
        [$from, $to] = $this->period();

        $appointment = Appointment::factory()->unverifiedPriority(pwd: true)->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'queue_date' => today()->toDateString(),
            'created_at' => now(),
        ]);

        app(QueueService::class)->refusePriority(
            $appointment,
            User::factory()->staff()->create(['service_window_id' => $this->window->id]),
            'Could not verify disability',
        );

        $report = $this->reports->priorityHandling($from, $to);

        $this->assertSame(1, $report['categories']['pwd']['refused']);
        $this->assertArrayHasKey('Could not verify disability', $report['refusal_reasons']);
        $this->assertSame(1, $report['refusal_reasons']['Could not verify disability']);
    }

    public function test_an_undecided_claim_is_reported_as_awaiting_rather_than_approved(): void
    {
        [$from, $to] = $this->period();

        Appointment::factory()->unverifiedPriority(pregnant: true)->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'queue_date' => today()->toDateString(),
            'created_at' => now(),
        ]);

        $pregnant = $this->reports->priorityHandling($from, $to)['categories']['pregnant'];

        $this->assertSame(1, $pregnant['claimed']);
        $this->assertSame(0, $pregnant['approved'], 'An unverified claim grants nothing yet.');
        $this->assertSame(1, $pregnant['awaiting_decision']);
        $this->assertNull($pregnant['approval_rate'], 'No decision has been made, so there is no rate.');
    }

    /* ------------------------------------------------------------------ */
    /* Shape of the day */
    /* ------------------------------------------------------------------ */

    public function test_peak_hours_always_returns_all_twenty_four_buckets(): void
    {
        [$from, $to] = $this->period();

        $slot = $this->slot(0, '09:00:00');

        Appointment::factory()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'status' => 'completed',
            'queue_date' => today()->toDateString(),
            'created_at' => now(),
            'checked_in_at' => now()->setTime(9, 0),
            'serving_started_at' => now()->setTime(9, 20),
            'completed_at' => now(),
        ]);

        $hours = $this->reports->peakHours($from, $to);

        $this->assertCount(24, $hours);

        // A quiet hour has to be present and explicitly zero, otherwise the chart
        // omits the finding that nobody came at 3pm.
        $this->assertSame(1, $hours[9]['arrivals']);
        $this->assertSame(0, $hours[15]['arrivals']);
        $this->assertNull($hours[15]['wait_average'], 'Nobody waited at 3pm, so there is no average.');
        $this->assertSame('3 PM', $hours[15]['label']);
    }

    public function test_the_daily_trend_is_filled_in_for_days_with_no_activity(): void
    {
        [$from, $to] = [today()->subDays(4)->startOfDay(), today()->startOfDay()];

        $slot = $this->slot(4, '09:00:00');

        Appointment::factory()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'status' => 'completed',
            'queue_date' => today()->subDays(4)->toDateString(),
            'created_at' => now()->subDays(4),
            'checked_in_at' => now()->subDays(4),
            'serving_started_at' => now()->subDays(4),
            'completed_at' => now()->subDays(4),
        ]);

        $trend = $this->reports->dailyTrend($from, $to);

        $this->assertCount(5, $trend);
        $this->assertSame(1, $trend[0]['completed']);
        $this->assertSame(0, $trend[1]['booked']);
        $this->assertSame(0, $trend[4]['completed']);
    }

    public function test_cancellation_reasons_are_ranked_and_blank_ones_excluded(): void
    {
        [$from, $to] = $this->period();

        $reasons = ['Schedule clash', 'Schedule clash', 'Wrong office', null, '   '];

        foreach ($reasons as $reason) {
            $appointment = Appointment::factory()->cancelled()->create([
                'service_id' => $this->service->id,
                'service_window_id' => $this->window->id,
                'queue_date' => today()->toDateString(),
                'created_at' => now(),
            ]);

            // Set after creation so the factory's own default reason cannot mask
            // what is actually being stored.
            $appointment->forceFill(['cancel_reason' => $reason])->save();
        }

        $grouped = $this->reports->cancellationReasons($from, $to);

        // Whitespace is not a reason, so it is excluded alongside null.
        $this->assertCount(2, $grouped);
        $this->assertSame('Schedule clash', $grouped->first()['reason']);
        $this->assertSame(2, $grouped->first()['total']);

        foreach ($grouped as $row) {
            $this->assertNotSame('', trim((string) $row['reason']));
        }
    }

    /* ------------------------------------------------------------------ */
    /* The estimate column is now actually written */
    /* ------------------------------------------------------------------ */

    /**
     * estimated_wait_minutes existed as a fillable, cast column that nothing ever
     * wrote to: the estimate was computed on the fly for the live display and
     * discarded. That left no record of what a client was actually told, so
     * estimate accuracy could not be measured at all.
     *
     * @return array<string, mixed>
     */
    private function checkInQueue(int $count = 3): array
    {
        $window = $this->window;
        $service = $this->service;
        $date = today()->toDateString();
        $queue = app(QueueService::class);
        $booking = app(BookingService::class);
        $stored = [];

        for ($i = 0; $i < $count; $i++) {
            $startAt = today()->addDay()->setTime(9, 0)->addMinutes($i * 30);

            $slot = TimeSlot::create([
                'service_id' => $service->id,
                'service_window_id' => $window->id,
                'slot_date' => today()->addDay()->toDateString(),
                'start_time' => $startAt->format('H:i:s'),
                'end_time' => $startAt->copy()->addMinutes(30)->format('H:i:s'),
                'start_at' => today()->addDay()->toDateString().' '.$startAt->format('H:i:s'),
                'end_at' => today()->addDay()->toDateString().' '.$startAt->copy()->addMinutes(30)->format('H:i:s'),
                'capacity' => 10,
                'booked_count' => 0,
                'is_open' => true,
            ]);

            $client = User::factory()->client()->create();

            $appointment = $booking->book($client, $service, $slot->id);
            $appointment->forceFill(['queue_date' => $date])->save();

            $checkedIn = $queue->checkIn($appointment->fresh());

            $stored[] = $checkedIn->estimated_wait_minutes;
        }

        return $stored;
    }

    public function test_checking_in_records_the_estimate_the_client_was_given(): void
    {
        $stored = $this->checkInQueue();

        // Every ticket has a value: none may be left null.
        $this->assertCount(3, $stored);

        foreach ($stored as $value) {
            $this->assertNotNull($value, 'The estimate column must be written at check-in.');
        }

        // First in line is told zero, and each place forward adds the window's
        // average service time of 15 minutes.
        $this->assertSame(0, $stored[0]);
        $this->assertSame(15, $stored[1]);
        $this->assertSame(30, $stored[2]);
    }

    public function test_the_recorded_estimate_is_persisted_not_just_returned_in_memory(): void
    {
        $this->checkInQueue(1);

        $stored = Appointment::where('status', 'checked_in')
            ->whereNotNull('estimated_wait_minutes')
            ->count();

        $this->assertSame(1, $stored, 'The estimate must reach the database.');
    }

    public function test_booking_alone_does_not_record_an_estimate(): void
    {
        // A booking made this morning for next week has no meaningful wait yet;
        // the estimate only becomes meaningful when the client joins the queue.
        $slot = $this->futureSlot(1, '09:00:00', capacity: 10);

        $appointment = app(BookingService::class)
            ->book(User::factory()->client()->create(), $this->service, $slot->id);

        $this->assertNull($appointment->estimated_wait_minutes);
    }

    /* ------------------------------------------------------------------ */
    /* Period handling */
    /* ------------------------------------------------------------------ */

    public function test_the_default_period_is_the_last_thirty_days(): void
    {
        [$from, $to, $days] = $this->reports->periodFrom(null, null, null);

        $this->assertSame(30, $days);
        $this->assertSame(today()->subDays(29)->toDateString(), $from->toDateString());
        $this->assertSame(today()->toDateString(), $to->toDateString());
    }

    public function test_presets_are_honoured(): void
    {
        foreach (['7' => 7, '30' => 30, '90' => 90, '180' => 180] as $preset => $expected) {
            [, , $days] = $this->reports->periodFrom($preset, null, null);

            $this->assertSame($expected, $days, "Preset {$preset} should span {$expected} days.");
        }
    }

    public function test_an_unknown_preset_falls_back_to_thirty_days(): void
    {
        [, , $days] = $this->reports->periodFrom('nonsense', null, null);

        $this->assertSame(30, $days);
    }

    public function test_swapped_custom_dates_are_corrected_rather_than_returning_nothing(): void
    {
        [$from, $to, $days] = $this->reports->periodFrom(null, '2026-12-31', '2026-01-01');

        $this->assertSame('2026-01-01', $from->toDateString());
        $this->assertSame('2026-12-31', $to->toDateString());
        $this->assertSame(365, $days);
    }

    public function test_custom_dates_win_over_a_preset(): void
    {
        [$from, $to] = $this->reports->periodFrom('7', '2026-03-01', '2026-03-05');

        $this->assertSame('2026-03-01', $from->toDateString());
        $this->assertSame('2026-03-05', $to->toDateString());
    }

    /* ------------------------------------------------------------------ */
    /* Window view */
    /* ------------------------------------------------------------------ */

    public function test_window_performance_survives_a_window_with_no_activity(): void
    {
        [$from, $to] = $this->period();

        $rows = $this->reports->windowPerformance($from, $to);

        $row = $rows->firstWhere('window.id', $this->window->id);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['booked']);
        $this->assertNull($row['avg_wait']);
        $this->assertNull($row['no_show_rate'], 'A rate over an empty window must be null.');
        $this->assertSame(0.0, $row['fees_collected']);
    }
}
