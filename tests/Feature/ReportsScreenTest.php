<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\QueueService;
use App\Services\ReportingService;
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

    /** Parse a rendered page so attributes can be asserted, not just text. */
    private function dom(string $content): \DOMXPath
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($content);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

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
    /* PDF export */
    /* ------------------------------------------------------------------ */

    public function test_the_pdf_export_is_a_pdf_naming_the_period(): void
    {
        $this->completedOn(today()->toDateString(), 25, 30);

        $response = $this->actingAs($this->admin)->get('/admin/reports/export/pdf');

        $response->assertOk();
        $response->assertDownload('govqueue-report-'.
            today()->subDays(29)->toDateString().'-to-'.today()->toDateString().'.pdf');

        // The magic number, rather than the content type: a renderer that
        // silently produced an empty document would still be served as
        // application/pdf, and that is the failure worth catching.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_pdf_is_refused_to_the_same_people_as_the_csv(): void
    {
        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        $this->actingAs($staff)->get('/admin/reports/export/pdf')->assertForbidden();
        $this->actingAs(User::factory()->client()->create())->get('/admin/reports/export/pdf')->assertForbidden();

        // 403, not a redirect: the admin group's role middleware refuses a
        // guest outright, the same as it does for the CSV and the screen.
        $this->get('/admin/reports/export/pdf')->assertForbidden();
    }

    public function test_a_pdf_with_no_activity_still_renders(): void
    {
        // An empty report that fails to render is worse than a blank one: the
        // administrator concludes the export is broken rather than that the
        // office was quiet.
        Service::query()->delete();

        $response = $this->actingAs($this->admin)->get('/admin/reports/export/pdf');

        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_pdf_carries_the_statutory_limitation_and_the_empty_period(): void
    {
        /*
         * The template is rendered directly rather than scraped out of the PDF.
         * The caveat travels with the printed document and has to be legible to
         * whoever reads it, and the string assertions below are the parts that
         * must never be dropped in a redesign.
         */
        $reports = app(ReportingService::class);
        $from = today()->subDays(6);

        $html = view('admin.reports.pdf', [
            'from' => $from,
            'to' => today(),
            'days' => 7,
            'summary' => $reports->summary($from, today()),
            'services' => collect(),
            'windows' => collect(),
            'peakHours' => $reports->peakHours($from, today()),
            'priority' => $reports->priorityHandling($from, today()),
            'presets' => [],
            'office' => 'LGU of Tupi',
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('RA 9994', $html);
        $this->assertStringContainsString('does not verify statutory eligibility', $html);
        $this->assertStringContainsString('No services had any activity', $html);

        // All three categories are listed even with nothing claimed, so the
        // zeroes have to say they are real rather than look like missing data.
        $this->assertStringContainsString('No client claimed a priority category', $html);
        $this->assertStringContainsString('Senior Citizen', $html);
        $this->assertStringContainsString('Person with Disability', $html);

        // Every one of the 24 hours is listed, empty ones included: a missing
        // hour reads as "no data collected" rather than "nobody came".
        $this->assertSame(24, substr_count($html, 'done'));
    }

    public function test_the_reports_screen_offers_both_download_formats(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/reports')
            ->assertOk()
            ->assertSee(route('admin.reports.export.pdf'), escape: false)
            ->assertSee(route('admin.reports.export'), escape: false);
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

        $headers = $this->dom($content)->query('//th');
        $this->assertGreaterThan(0, $headers->length);

        foreach ($headers as $th) {
            $this->assertTrue(
                $th->hasAttribute('scope') || $th->hasAttribute('headers'),
                'A table header on the reports screen has no scope.'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Date bounds */
    /* ------------------------------------------------------------------ */

    public function test_the_date_picker_is_bounded_to_a_range_that_can_hold_data(): void
    {
        $this->completedOn(today()->toDateString(), 25, 30);

        $content = $this->actingAs($this->admin)->get('/admin/reports')->assertOk()->getContent();
        $xp = $this->dom($content);

        foreach (['from', 'to'] as $field) {
            $nodes = $xp->query(sprintf('//*[@id=%s]', json_encode($field)));

            $this->assertSame(1, $nodes->length, "One {$field} input, not zero or two.");

            $input = $nodes->item(0);

            $this->assertSame('date', $input->getAttribute('type'));
            $this->assertSame(
                today()->toDateString(),
                $input->getAttribute('max'),
                "{$field} should not offer a date in the future: there is no data to report."
            );
            $this->assertNotSame('', $input->getAttribute('min'));
        }
    }

    public function test_the_floor_follows_the_data_rather_than_a_hard_coded_date(): void
    {
        // A fixed floor would be wrong on a fresh installation with no history,
        // and would need editing every time the oldest record aged out.
        $this->completedOn(today()->subDays(40)->toDateString(), 25, 30);

        $content = $this->actingAs($this->admin)->get('/admin/reports')->assertOk()->getContent();
        $xp = $this->dom($content);

        $from = $xp->query('//*[@id="from"]')->item(0);

        $this->assertSame(
            today()->subDays(40)->toDateString(),
            $from->getAttribute('min'),
        );
    }

    public function test_the_audit_date_picker_is_bounded_too(): void
    {
        $admin = User::factory()->admin()->create();

        AuditLog::record('window.created', $admin, []);

        $content = $this->actingAs($admin)->get('/admin/audit')->assertOk()->getContent();
        $xp = $this->dom($content);

        $from = $xp->query('//*[@id="from"]')->item(0);

        $this->assertSame(today()->toDateString(), $from->getAttribute('max'));
        $this->assertNotSame('', $from->getAttribute('min'));
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
