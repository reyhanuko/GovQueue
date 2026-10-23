<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The figures on the reports screen must add up, and must say what they mean.
 *
 * Reported as "numbers do not reconcile: Appointments 216, Bookings made 176,
 * decided 208, Completed 181, No-show 27, Skipped 4, Cancelled 0", and "40
 * appointments vs 27+5+4=36 accounted" in the seven-day view.
 *
 * The arithmetic was never wrong - completed + cancelled + no-show = decided,
 * and the population minus decided was the remainder. What was missing was the
 * remainder itself, and any statement that "skipped" is not an outcome. A reader
 * adding up the visible numbers got a total short of the headline, with no way to
 * tell whether that was a bug or simply work still to do.
 */
class ReportReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private ?TimeSlot $slot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'CERT-IND',
            'is_active' => true,
        ]);
    }

    /**
     * One shared slot.
     *
     * time_slots is unique on (service_id, slot_date, start_time), so creating a
     * fresh 09:00 slot per appointment raised a constraint violation the second
     * time round. One slot with room for them all is also closer to reality.
     */
    private function slot(): TimeSlot
    {
        if ($this->slot) {
            return $this->slot;
        }

        $start = today()->setTime(9, 0);

        return $this->slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 50,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    /** An appointment dated today in a given state. */
    private function appointment(string $status): Appointment
    {
        $slot = $this->slot();

        return Appointment::factory()->create([
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'scheduled_at' => $slot->start_at,
            'queue_date' => $slot->slot_date,
            'status' => $status,
        ]);
    }

    private function summary(): array
    {
        return app(ReportingService::class)->summary(today()->startOfDay(), today()->endOfDay());
    }

    public function test_the_outcomes_and_the_remainder_reach_the_population(): void
    {
        $this->appointment('completed');
        $this->appointment('completed');
        $this->appointment('cancelled');
        $this->appointment('no_show');
        // Not decided yet - this is the bucket that was missing from the screen.
        $this->appointment('pending');
        $this->appointment('confirmed');

        $s = $this->summary();

        $this->assertSame(6, $s['in_period']);
        $this->assertSame(2, $s['completed']);
        $this->assertSame(1, $s['cancelled']);
        $this->assertSame(1, $s['no_show']);
        $this->assertSame(2, $s['still_open']);

        // The identity the screen has to be able to state.
        $this->assertSame(
            $s['in_period'],
            $s['completed'] + $s['cancelled'] + $s['no_show'] + $s['still_open'],
            'The reported figures do not add up to the population, so there is again nothing to show a reader.',
        );
    }

    public function test_cancellations_are_counted(): void
    {
        $this->appointment('cancelled');

        $this->assertSame(1, $this->summary()['cancelled']);
    }

    /**
     * Skips overlap outcomes, so they must never be part of the sum. A skipped
     * ticket that was still served appears in both figures, and adding them
     * double-counts it.
     */
    public function test_skipped_is_not_an_outcome_and_is_not_in_the_sum(): void
    {
        $skippedAndServed = $this->appointment('completed');
        $skippedAndServed->update(['skip_count' => 1]);

        $s = $this->summary();

        $this->assertSame(1, $s['skipped']);
        $this->assertSame(1, $s['completed']);

        // Adding skipped would produce 2 for a single ticket.
        $this->assertSame(
            $s['in_period'],
            $s['completed'] + $s['cancelled'] + $s['no_show'] + $s['still_open'],
        );
        $this->assertNotSame(
            $s['in_period'],
            $s['completed'] + $s['cancelled'] + $s['no_show'] + $s['still_open'] + $s['skipped'],
            'Skipped must stay outside the outcome sum.',
        );
    }

    public function test_the_screen_states_the_reconciliation_and_the_remainder(): void
    {
        $this->appointment('completed');
        $this->appointment('pending');

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/reports')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Still open', $html);
        $this->assertStringContainsString('Not an outcome yet', $html);
        $this->assertStringContainsString('still open', $html);
    }

    public function test_the_screen_says_skipped_is_not_added(): void
    {
        $this->appointment('completed');

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/reports')
            ->assertOk()
            // Matched without the emphasised word either side of it, since the sentence
// reads "is <em>not</em> added to the row above".
            ->assertSee('added to the row above', false);
    }

    /** Nothing here takes a payment, so the column must not claim money was taken. */
    public function test_the_screen_does_not_call_recorded_fees_collected(): void
    {
        $this->appointment('completed');

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/reports')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Fees collected', $html);
        $this->assertStringContainsString('Fees recorded', $html);
        $this->assertStringContainsString('does not take payment', $html);
    }

    /**
     * The PDF carries the same wording as the screen.
     *
     * Checked against the template rather than the rendered file: PDF text is
     * compressed, so asserting on the response body would pass whether or not the
     * label is right. The export itself is checked separately, below.
     */
    public function test_the_pdf_template_uses_the_same_wording(): void
    {
        $source = file_get_contents(resource_path('views/admin/reports/pdf.blade.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('Fees recorded', $source);
        $this->assertStringNotContainsString(
            'Fees collected',
            $source,
            'The PDF still claims money was collected, so the two documents disagree.',
        );
    }

    public function test_the_pdf_export_still_runs(): void
    {
        $this->appointment('completed');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.reports.export.pdf'))
            ->assertOk();
    }
}
