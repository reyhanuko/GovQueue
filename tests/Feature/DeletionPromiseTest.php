<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\ScheduleAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Delete buttons must not promise what the server will refuse to do.
 *
 * Reported: the services table offered "Barangay Clearance and its slot templates
 * will be removed. Bookings already made are kept.", and the request then failed
 * with "cannot delete, 34 appointments reference it". The windows table offered
 * "Window 1 and its services will be removed" and then refused with "4 services
 * still assigned" - the one thing the dialog had just promised would happen.
 *
 * The cause was two copies of the rule. The promise lived in a Blade template and
 * the enforcement lived in ScheduleAdminService, so they could not agree and
 * nothing failed until somebody pressed the button. Both now ask the same method,
 * and these tests hold them to it.
 */
class DeletionPromiseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        // unstaffed(): several tests here assert that this window is genuinely
        // empty - no services and no staff - which is what makes it deletable.
        // The factory staffs a window by default (decision D2), and an assigned
        // clerk is a dependency that would block the deletion.
        $this->window = ServiceWindow::factory()->unstaffed()->create(['code' => 'WINDOW_1']);
    }

    private function service(array $attributes = []): Service
    {
        return Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'CERT-IND',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function slot(Service $service, array $attributes = []): TimeSlot
    {
        $start = now()->addDays(3)->setTime(9, 0);

        return TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $service->service_window_id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 4,
            'booked_count' => 0,
            'is_open' => true,
            ...$attributes,
        ]);
    }

    private function booked(Service $service, TimeSlot $slot): Appointment
    {
        return Appointment::factory()->create([
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $slot->service_window_id,
            'scheduled_at' => $slot->start_at,
            'queue_date' => $slot->slot_date,
            'status' => 'confirmed',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* A blocked delete says so before the button is pressed */
    /* ------------------------------------------------------------------ */

    public function test_a_service_with_appointments_cannot_be_deleted_and_says_why(): void
    {
        $service = $this->service();
        $this->booked($service, $this->slot($service));

        $html = $this->actingAs($this->admin)->get('/admin/services')->assertOk()->getContent();

        // The reason, next to the button.
        $this->assertStringContainsString(
            'Cannot delete',
            $html,
            'The services screen does not explain why Delete is unavailable.',
        );
        $this->assertStringContainsString('appointment(s) reference it', $html);
    }

    public function test_a_blocked_service_delete_is_not_offered_as_a_confirmation(): void
    {
        $service = $this->service();
        $this->booked($service, $this->slot($service));

        $html = $this->actingAs($this->admin)->get('/admin/services')->assertOk()->getContent();

        // The old text promised a deletion that cannot happen. It must be gone
        // whenever the deletion is blocked, which is the whole point.
        $this->assertStringNotContainsString(
            'Bookings already made are kept',
            $html,
            'The confirmation still promises a deletion the server will refuse.',
        );

        // And the button itself is inert.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*disabled[^>]*>\s*Delete/s',
            $html,
            'A blocked Delete is still clickable.',
        );
    }

    public function test_a_service_with_open_future_slots_cannot_be_deleted(): void
    {
        $service = $this->service();
        $this->slot($service);

        $html = $this->actingAs($this->admin)->get('/admin/services')->assertOk()->getContent();

        $this->assertStringContainsString('bookable slot(s) remain', $html);
    }

    public function test_a_deletable_service_offers_a_confirmation_that_states_what_happens(): void
    {
        $service = $this->service();
        SlotTemplate::create([
            'service_id' => $service->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_minutes' => 30,
            'capacity' => 5,
            'effective_from' => today()->toDateString(),
            'is_active' => true,
        ]);

        $html = $this->actingAs($this->admin)->get('/admin/services')->assertOk()->getContent();

        // Nothing depends on it, so a real confirmation is appropriate - and it
        // names the templates, because that is what actually goes.
        $this->assertStringContainsString('slot template(s) will be removed', $html);
        $this->assertStringNotContainsString('Bookings already made are kept', $html);
    }

    public function test_a_window_with_services_cannot_be_deleted_and_does_not_promise_otherwise(): void
    {
        $this->service();

        $html = $this->actingAs($this->admin)->get('/admin/windows')->assertOk()->getContent();

        $this->assertStringContainsString('service(s) are still assigned to it', $html);

        // This is the exact contradiction that was reported: the dialog promised
        // the services would be removed, and the server refuses because they exist.
        $this->assertStringNotContainsString('and its services will be removed', $html);
    }

    public function test_an_empty_window_can_be_deleted_and_says_only_that(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/windows')->assertOk()->getContent();

        $this->assertStringContainsString('will be removed. It has no services and no staff', $html);
    }

    /* ------------------------------------------------------------------ */
    /* The screen and the guard are the same rule */
    /* ------------------------------------------------------------------ */

    public function test_the_screen_agrees_with_the_guard_about_every_service(): void
    {
        $blocked = $this->service(['code' => 'BLOCKED']);
        $this->booked($blocked, $this->slot($blocked));

        $deletable = $this->service(['code' => 'FREE']);

        $admin = app(ScheduleAdminService::class);

        // Whatever the screen offers, the server must agree with.
        $this->assertNotNull(
            $admin->deletionBlockerForService($blocked),
            'A booked service must be blocked, or the screen will promise a doomed delete.',
        );
        $this->assertNull(
            $admin->deletionBlockerForService($deletable),
            'A service with nothing attached must be deletable, or the screen will refuse a safe delete.',
        );

        $html = $this->actingAs($this->admin)->get('/admin/services')->assertOk()->getContent();

        $this->assertStringContainsString('appointment(s) reference it', $html);
    }

    public function test_the_window_blocker_reports_services_before_staff(): void
    {
        $this->service();

        $blocker = app(ScheduleAdminService::class)->deletionBlockerForWindow($this->window);

        $this->assertNotNull($blocker);
        $this->assertStringContainsString('service(s) are still assigned', $blocker->getMessage());
        $this->assertNotNull($blocker->hint, 'A blocked action must say what to do instead.');
    }

    /* ------------------------------------------------------------------ */
    /* The window code is an identifier, not a label */
    /* ------------------------------------------------------------------ */

    public function test_a_window_code_is_locked_once_anything_depends_on_it(): void
    {
        $this->service();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.windows.edit', $this->window))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('This code is now locked', $html);
        $this->assertMatchesRegularExpression(
            '/name="code"[^>]*disabled|disabled[^>]*name="code"/s',
            $html,
            'The code field is still editable after services are attached.',
        );
    }

    public function test_a_window_code_is_editable_before_anything_depends_on_it(): void
    {
        // unstaffed(): the factory staffs a window by default (decision D2), and
        // an assigned clerk is a dependency, so this "nothing depends on it"
        // window has to genuinely have nobody.
        $window = ServiceWindow::factory()->unstaffed()->create(['code' => 'EMPTY_ONE']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.windows.edit', $window))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('This code is now locked', $html);
    }

    /**
     * A disabled input is not submitted, so saving an untouched window would
     * otherwise fail validation on a code the administrator never changed.
     */
    public function test_a_locked_code_can_still_be_saved(): void
    {
        $this->service();

        $this->actingAs($this->admin)
            ->put(route('admin.windows.update', $this->window), [
                'name' => 'Renamed window',
                'code' => 'WINDOW_1',
                'avg_service_minutes' => 15,
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed window', $this->window->fresh()->name);
    }

    public function test_a_new_window_has_an_editable_code(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/windows/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('This code is now locked', $html);
    }
}
