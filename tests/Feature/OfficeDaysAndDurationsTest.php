<?php

namespace Tests\Feature;

use App\Exceptions\AdminActionBlockedException;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\HistoricalDurationService;
use App\Services\ScheduleAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decision D3: strict open-day validation, and measured wait estimates.
 *
 * Two separate halves.
 *
 * "Enforce strict open-day validation" - finding F-08(e): Saturday templates
 * existed while Sunday was inactive, and a Saturday that was also a declared
 * holiday still showed slots. Two schedules claiming to describe when this office
 * opens, and neither of them true.
 *
 * "Use historical transaction times for wait estimates" - services.duration_minutes
 * is what somebody typed when the service was set up. Every completed appointment
 * has already recorded serving_started_at and completed_at, so the real figure has
 * been captured all along and never used.
 */
class OfficeDaysAndDurationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['govqueue.open_days' => [1, 2, 3, 4, 5]]);
        config(['govqueue.duration_min_samples' => 5]);

        $this->admin = User::factory()->admin()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'CERT-IND',
            'name' => 'Certificate of Indigency',
            'duration_minutes' => 20,
        ]);
    }

    private function templateData(array $overrides = []): array
    {
        return [
            'service_id' => $this->service->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_minutes' => 30,
            'capacity' => 4,
            'effective_from' => today()->toDateString(),
            ...$overrides,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Open days */
    /* ------------------------------------------------------------------ */

    public function test_a_weekday_template_is_accepted(): void
    {
        $template = app(ScheduleAdminService::class)->createTemplate($this->templateData());

        $this->assertSame('Monday', $template->dayName());
    }

    public function test_a_saturday_template_is_refused(): void
    {
        // 6 = Saturday
        $this->expectException(AdminActionBlockedException::class);

        app(ScheduleAdminService::class)->createTemplate($this->templateData(['day_of_week' => 6]));
    }

    public function test_a_sunday_template_is_refused(): void
    {
        // 0 = Sunday
        $this->expectException(AdminActionBlockedException::class);

        app(ScheduleAdminService::class)->createTemplate($this->templateData(['day_of_week' => 0]));
    }

    /** The message has to say what is wrong and what to do. */
    public function test_the_refusal_names_the_day_and_the_setting(): void
    {
        try {
            app(ScheduleAdminService::class)->createTemplate($this->templateData(['day_of_week' => 6]));
            $this->fail('A Saturday template was accepted.');
        } catch (AdminActionBlockedException $e) {
            $this->assertStringContainsString('Saturday', $e->getMessage());
            // The open days belong in the hint, which is where the actionable
            // detail lives; the message says what is wrong, the hint says what to
            // do about it.
            $this->assertStringContainsString('Monday to Friday', $e->hint);
            $this->assertStringContainsString('setting', $e->hint);
        }
    }

    public function test_no_template_is_written_when_refused(): void
    {
        try {
            app(ScheduleAdminService::class)->createTemplate($this->templateData(['day_of_week' => 0]));
        } catch (AdminActionBlockedException) {
            // Expected.
        }

        $this->assertSame(0, SlotTemplate::count());
    }

    /**
     * An office that does open at the weekend can say so.
     *
     * A Philippine LGU may hold a Saturday market or assembly, and the queue comes
     * with it. Blocking Saturday outright would be wrong for them, so the days are
     * a setting rather than a hard-coded rule.
     */
    public function test_a_weekend_office_can_configure_saturday(): void
    {
        config(['govqueue.open_days' => [1, 2, 3, 4, 5, 6]]);

        $template = app(ScheduleAdminService::class)->createTemplate($this->templateData(['day_of_week' => 6]));

        $this->assertSame('Saturday', $template->dayName());
    }

    /**
     * The guard has to survive an edit, not just the original save.
     *
     * Otherwise narrowing the open days leaves existing Saturday templates in
     * place, still generating slots, and the decision is only half applied.
     */
    public function test_updating_a_template_into_a_closed_day_is_refused(): void
    {
        config(['govqueue.open_days' => [0, 1, 2, 3, 4, 5, 6]]);

        $template = app(ScheduleAdminService::class)->createTemplate($this->templateData(['day_of_week' => 6]));

        config(['govqueue.open_days' => [1, 2, 3, 4, 5]]);

        $this->expectException(AdminActionBlockedException::class);

        app(ScheduleAdminService::class)->updateTemplate($template, [
            'day_of_week' => 6,
            'capacity' => 6,
        ]);
    }

    /** An empty setting means "no restriction", not "closed every day". */
    public function test_an_empty_open_days_list_blocks_nothing(): void
    {
        config(['govqueue.open_days' => []]);

        $template = app(ScheduleAdminService::class)->createTemplate($this->templateData(['day_of_week' => 6]));

        $this->assertSame('Saturday', $template->dayName());
    }

    /** The form should not offer a day that would be refused. */
    public function test_the_form_offers_only_open_days(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.templates.index', $this->service))
            ->assertOk()
            ->getContent();

        // The day labels are written on their own line inside the option, so the
        // surrounding markup is collapsed before matching.
        $flat = preg_replace('/\s+/', '', $html);

        $this->assertStringContainsString('>Monday<', $flat);
        $this->assertStringNotContainsString('>Saturday<', $flat);
    }

    /* ------------------------------------------------------------------ */
    /* Measured durations */
    /* ------------------------------------------------------------------ */

    /**
     * Record a completed appointment of a given length.
     *
     * Both stamps are set, because an appointment missing either one cannot be
     * measured - a client who checked in and never got served is not evidence of
     * how long the service takes.
     */
    private function complete(int $minutes, int $daysAgo = 1): void
    {
        $start = now()->subDays($daysAgo)->setTime(9, 0);

        $client = User::factory()->client()->create();

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addHours(3)->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        Appointment::factory()->create([
            'user_id' => $client->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'scheduled_at' => $start->toDateTimeString(),
            'queue_date' => $start->toDateString(),
            'status' => 'completed',
            'serving_started_at' => $start->toDateTimeString(),
            'completed_at' => $start->copy()->addMinutes($minutes)->toDateTimeString(),
        ]);
    }

    public function test_a_service_with_no_history_reports_the_configured_duration(): void
    {
        $result = app(HistoricalDurationService::class)->forService($this->service);

        $this->assertSame(20, $result['minutes']);
        $this->assertFalse($result['measured']);
    }

    /**
     * The point of the whole change.
     *
     * Configured at 20, actually takes 45. Once there is enough history, 45 is what
     * a client is told.
     */
    public function test_the_measured_duration_replaces_the_configured_one(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->complete(45, $i);
        }

        $result = app(HistoricalDurationService::class)->forService($this->service);

        $this->assertSame(45, $result['minutes']);
        $this->assertTrue($result['measured']);
        $this->assertSame(6, $result['samples']);
    }

    /**
     * Too little history is still a guess.
     *
     * Two completed appointments is not a measurement. Quoting 45 from two rows
     * would be overconfident, and the configured 20 is the safer thing to say.
     */
    public function test_too_little_history_falls_back_to_the_configured_duration(): void
    {
        $this->complete(45, 1);
        $this->complete(45, 2);

        $result = app(HistoricalDurationService::class)->forService($this->service);

        $this->assertSame(20, $result['minutes']);
        $this->assertFalse($result['measured']);
        $this->assertSame(2, $result['samples']);
    }

    /**
     * One appointment left open over lunch must not move the figure.
     *
     * 92 minutes is real data and it is also a clerk who stopped for lunch. A mean
     * would follow it; a median does not. This is the reason for the median.
     */
    public function test_one_very_long_appointment_does_not_move_the_estimate(): void
    {
        foreach ([12, 14, 13, 15, 13] as $i => $minutes) {
            $this->complete($minutes, $i + 1);
        }

        // The lunch break: real, and unrepresentative.
        $this->complete(92, 6);

        $result = app(HistoricalDurationService::class)->forService($this->service);

        // Six samples, so the median is the mean of the 3rd and 4th sorted values:
        // 13 and 14. The 92 is nowhere near either, which is the whole point - a
        // mean over these six would give 26.
        $this->assertSame(
            14,
            $result['minutes'],
            'A single 92-minute outlier moved the estimate, so the median is not being used.',
        );
    }

    /** An appointment that never got served is not evidence of anything. */
    public function test_appointments_missing_a_stamp_are_ignored(): void
    {
        foreach ([12, 14, 13, 15, 13, 14] as $i => $minutes) {
            $this->complete($minutes, $i + 1);
        }

        // Checked in, never served.
        $start = now()->subDay()->setTime(11, 0);

        Appointment::factory()->create([
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'scheduled_at' => $start->toDateTimeString(),
            'queue_date' => $start->toDateString(),
            'status' => 'no_show',
            'serving_started_at' => $start->toDateTimeString(),
            'completed_at' => null,
        ]);

        $result = app(HistoricalDurationService::class)->forService($this->service);

        $this->assertSame(6, $result['samples'], 'A no-show was counted as a measurement.');
        $this->assertSame(14, $result['minutes']);
    }

    /* ------------------------------------------------------------------ */
    /* What the client is shown */
    /* ------------------------------------------------------------------ */

    public function test_the_client_is_shown_the_measured_duration(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->complete(45, $i);
        }

        $html = $this->actingAs(User::factory()->client()->create())
            ->get(route('client.booking.create', $this->service))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('about 45 minutes', $html);
        $this->assertStringNotContainsString('about 20 minutes', $html);
    }

    /** With no history, the client still sees the configured figure. */
    public function test_the_client_sees_the_configured_duration_when_unmeasured(): void
    {
        $html = $this->actingAs(User::factory()->client()->create())
            ->get(route('client.booking.create', $this->service))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('about 20 minutes', $html);
    }
}
