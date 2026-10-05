<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reports screen and its CSV export.
 *
 * Access control matters more here than usual: the screen aggregates client
 * behaviour across the whole office, so leaking it to staff or clients would
 * expose how many people missed appointments and why.
 */
class ReportsScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'RPT-SCREEN',
            'name' => 'Reportable Service',
        ]);
    }

    private function slot(string $date, string $start = '09:00:00', int $capacity = 4): TimeSlot
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

    private function completedOn(string $date, int $waitMinutes = 20, ?int $estimate = null): Appointment
    {
        $slot = $this->slot($date);

        return Appointment::factory()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'status' => 'completed',
            'queue_date' => $date,
            'created_at' => now(),
            'checked_in_at' => now()->subMinutes($waitMinutes),
            'serving_started_at' => now(),
            'completed_at' => now(),
            'estimated_wait_minutes' => $estimate,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Access control */
    /* ------------------------------------------------------------------ */

    public function test_clients_cannot_see_reports(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/admin/reports')
            ->assertForbidden();

        $this->actingAs(User::factory()->client()->create())
            ->get('/admin/reports/export')
            ->assertForbidden();
    }

    public function test_staff_cannot_see_reports_or_export_them(): void
    {
        // The screen reveals how many clients missed appointments and why, which
        // is office-wide information. Administrators only.
        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        $this->actingAs($staff)->get('/admin/reports')->assertForbidden();
        $this->actingAs($staff)->get('/admin/reports/export')->assertForbidden();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/admin/reports')->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------ */
    /* Rendering */
    /* ------------------------------------------------------------------ */

    public function test_the_screen_renders_with_no_data_at_all(): void
    {
        // The important case: a brand new installation must not show a wall of
        // zeros that read as "we served nobody instantly".
        $response = $this->actingAs($this->admin)->get('/admin/reports');

        $response->assertOk();
        $response->assertSee('Reports');
        $response->assertSee('No data', escape: false);
        $response->assertSee('nothing to measure in this period');
        $response->assertSee('No slots offered');
    }

    public function test_the_screen_shows_the_chosen_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/reports?period=7')
            ->assertOk()
            ->assertSee('Last 7 days')
            ->assertSee('7 days');

        $this->actingAs($this->admin)
            ->get('/admin/reports?from=2026-03-01&to=2026-03-05')
            ->assertOk()
            ->assertSee('1 Mar 2026')
            ->assertSee('5 Mar 2026');
    }

    public function test_swapped_dates_still_render_a_sensible_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/reports?from=2026-12-31&to=2026-01-01')
            ->assertOk()
            ->assertSee('1 Jan 2026')
            ->assertSee('31 Dec 2026');
    }

    public function test_real_activity_appears_on_the_screen(): void
    {
        $this->completedOn(today()->toDateString(), 25, 30);

        $this->actingAs($this->admin)
            ->get('/admin/reports')
            ->assertOk()
            ->assertSee('Reportable Service')
            ->assertSee('Demand against capacity')
            ->assertSee('Priority claims');
    }

    public function test_the_screen_explains_a_service_with_no_slots(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/reports')
            ->assertOk()
            ->assertSee('No slots offered');
    }

    public function test_the_screen_names_the_reasons_staff_refused_priority(): void
    {
        $appointment = Appointment::factory()->unverifiedPriority(senior: true)->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'queue_date' => today()->toDateString(),
            'created_at' => now(),
        ]);

        app(QueueService::class)->refusePriority(
            $appointment,
            User::factory()->staff()->create(['service_window_id' => $this->window->id]),
            'No valid ID presented',
        );

        $this->actingAs($this->admin)
            ->get('/admin/reports')
            ->assertOk()
            ->assertSee('No valid ID presented')
            ->assertSee('Senior Citizen')
            ->assertSee('RA 9994');
    }

    public function test_cancellation_reasons_are_listed(): void
    {
        $appointment = Appointment::factory()->cancelled()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'queue_date' => today()->toDateString(),
            'created_at' => now(),
        ]);

        $appointment->forceFill(['cancel_reason' => 'Client had no documents'])->save();

        $this->actingAs($this->admin)
            ->get('/admin/reports')
            ->assertOk()
            ->assertSee('Client had no documents')
            ->assertSee('Why appointments were cancelled');
    }

    /* ------------------------------------------------------------------ */
    /* Export */
    /* ------------------------------------------------------------------ */

    public function test_the_export_is_a_csv_with_headers_and_a_row_per_service(): void
    {
        $this->completedOn(today()->toDateString(), 25, 30);

        $response = $this->actingAs($this->admin)->get('/admin/reports/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertDownload('govqueue-report-'.

            today()->subDays(29)->toDateString().'-to-'.today()->toDateString().'.csv');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Service,Code,Window', $csv);
        $this->assertStringContainsString('Reportable Service', $csv);
        $this->assertStringContainsString('Utilisation %', $csv);
    }

    public function test_the_export_starts_with_a_byte_order_mark(): void
    {
        // Without the BOM, Excel mangles the peso sign and Filipino text.
        $csv = $this->actingAs($this->admin)
            ->get('/admin/reports/export')
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function test_an_export_with_no_services_still_says_so(): void
    {
        // An empty file that downloads successfully is the worst outcome: the
        // administrator assumes the report is fine.
        Service::query()->delete();

        $csv = $this->actingAs($this->admin)
            ->get('/admin/reports/export')
            ->streamedContent();

        $this->assertStringContainsString('No services configured', $csv);
    }

    public function test_the_export_honours_the_requested_period(): void
    {
        $this->completedOn(today()->toDateString(), 25, 30);

        $csv = $this->actingAs($this->admin)
            ->get('/admin/reports/export?period=7')
            ->streamedContent();

        $this->assertStringContainsString('Reportable Service', $csv);
    }

    public function test_the_export_writes_no_data_rather_than_a_zero_wait(): void
    {
        // A service with capacity but no completed work has no average wait. The
        // cell must say so rather than implying instant service.
        $this->slot(today()->toDateString());

        $csv = $this->actingAs($this->admin)
            ->get('/admin/reports/export')
            ->streamedContent();

        $this->assertStringContainsString('no data', $csv);
    }

    /* ------------------------------------------------------------------ */
    /* Navigation and accessibility */
    /* ------------------------------------------------------------------ */

    public function test_the_admin_navigation_links_to_the_reports(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee(route('admin.reports.index'), escape: false);
    }

    public function test_the_reports_tables_scope_their_headers(): void
    {
        $this->completedOn(today()->toDateString(), 25, 30);

        $content = $this->actingAs($this->admin)->get('/admin/reports')->assertOk()->getContent();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($content);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        $headers = $xp->query('//th');
        $this->assertGreaterThan(0, $headers->length);

        foreach ($headers as $th) {
            $this->assertTrue(
                $th->hasAttribute('scope') || $th->hasAttribute('headers'),
                'A table header on the reports screen has no scope.'
            );
        }
    }

    public function test_the_period_picker_controls_are_labelled(): void
    {
        $content = $this->actingAs($this->admin)->get('/admin/reports')->assertOk()->getContent();

        foreach (['period', 'from', 'to'] as $id) {
            $this->assertStringContainsString(
                'for="'.$id.'"',
                $content,
                "The {$id} control has no label, so it is unlabelled for assistive technology."
            );
        }
    }
}
