<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The ISO/IEC 25010 quality report.
 *
 * A report that quietly reports a stale or fabricated number is worse than no
 * report, because it is evidence. These tests check that it measures the system in
 * front of it rather than emitting plausible-looking prose.
 */
class QualityReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $args = []): string
    {
        // Artisan::call rather than $this->artisan(), because the latter hands
        // back a PendingCommand whose output buffer is not the one Artisan::output()
        // reads.
        $exit = Artisan::call('govqueue:quality-report', $args);

        $this->assertSame(0, $exit, 'The quality report must complete successfully.');

        return Artisan::output();
    }

    public function test_it_reports_on_every_characteristic_of_the_model(): void
    {
        $output = $this->report();

        foreach ([
            'Functional suitability',
            'Performance efficiency',
            'Compatibility',
            'Usability',
            'Reliability',
            'Security',
            'Maintainability',
            'Portability',
        ] as $characteristic) {
            $this->assertStringContainsString($characteristic, $output);
        }
    }

    public function test_it_names_the_standard_it_is_measuring_against(): void
    {
        $this->assertStringContainsString('ISO/IEC 25010', $this->report());
    }

    public function test_every_characteristic_carries_a_measure_a_result_and_evidence(): void
    {
        $output = $this->report();

        // An evaluation with a bare number and no evidence is an assertion, not a
        // measurement.
        $this->assertSame(8, substr_count($output, 'measure:'));
        $this->assertSame(8, substr_count($output, 'result:'));
        $this->assertSame(8, substr_count($output, 'evidence:'));
    }

    public function test_the_performance_figure_is_measured_not_asserted(): void
    {
        Service::factory()->create([
            'service_window_id' => ServiceWindow::factory()->create()->id,
        ]);

        $output = $this->report();

        // Query count and a wall time in milliseconds, both taken from the run.
        $this->assertMatchesRegularExpression(
            '/catalogue \d+ q \/ \d+ ms/',
            $output
        );
    }

    public function test_it_reports_the_actual_runtime_and_database(): void
    {
        $output = $this->report();

        $this->assertStringContainsString('PHP '.PHP_VERSION, $output);
        $this->assertStringContainsString('MYSQL', strtoupper($output));
    }

    public function test_it_counts_real_routes(): void
    {
        $output = $this->report();

        $this->assertMatchesRegularExpression(
            '/result:\s+\d+ routes \(\d+ named\)/',
            $output
        );
    }

    public function test_it_admits_the_paths_that_need_evidence_rather_than_only_code(): void
    {
        // Usability and security are judged by test names, and the report says so
        // instead of inventing a percentage.
        $output = $this->report();

        $this->assertStringContainsString('AccessibilityTest', $output);
        $this->assertStringContainsString('AuthenticationRateLimitTest', $output);
    }

    public function test_it_does_not_claim_a_perfect_score(): void
    {
        $output = $this->report();

        // A self-assessment that only ever says "excellent" is marketing.
        $this->assertStringNotContainsString('100%', $output);
    }

    public function test_it_can_emit_json(): void
    {
        $decoded = json_decode($this->report(['--json' => true]), true);

        $this->assertIsArray($decoded);
        $this->assertSame('ISO/IEC 25010', $decoded['standard']);
        $this->assertCount(8, $decoded['characteristics']);

        foreach ($decoded['characteristics'] as $row) {
            $this->assertArrayHasKey('characteristic', $row);
            $this->assertArrayHasKey('value', $row);
        }
    }

    public function test_it_works_on_an_empty_database(): void
    {
        // A fresh installation has no appointments at all. The report must still
        // produce figures rather than dividing by nothing.
        $this->assertSame(0, User::count());

        $this->assertStringContainsString('result:', $this->report());
    }

    public function test_it_does_not_leave_a_transaction_open(): void
    {
        // Measuring must not interfere with the booking lock, which is the one
        // place a stray transaction in this codebase would cause real harm.
        Service::factory()->create([
            'service_window_id' => ServiceWindow::factory()->create()->id,
        ]);

        $slot = TimeSlot::create([
            'service_id' => Service::first()->id,
            'service_window_id' => Service::first()->service_window_id,
            'slot_date' => today()->addDay()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => today()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => today()->addDay()->setTime(9, 30)->toDateTimeString(),
            'capacity' => 1,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        // RefreshDatabase already wraps this test in a transaction, so the level is
        // not zero to begin with. What matters is that measuring does not change
        // it - a stray transaction here would be the one thing in this codebase
        // capable of blocking the booking lock.
        $before = DB::transactionLevel();

        $this->report();

        $this->assertSame(
            $before,
            DB::transactionLevel(),
            'The quality report must not open or leave a transaction.'
        );

        // And the slot must still be bookable: nothing took a lock on it.
        $this->assertSame(0, $slot->fresh()->booked_count);
    }
}
