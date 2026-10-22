<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Administrator configuration: CRUD and, more importantly, the rules that stop an
 * administrator from breaking data clients already depend on.
 *
 * The guards matter because the schema's foreign keys are far too blunt a
 * instrument for this:
 *
 *  - appointments.service_id is restrictOnDelete, so deleting a booked service
 *    raises a raw integrity error - a 500 - for what is really a "deactivate it
 *    instead" situation.
 *  - services.service_window_id is cascadeOnDelete, so deleting a window would
 *    silently delete every service in it.
 *
 * So the checks belong here, and these tests are what keep them honest.
 */
class AdminConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceWindow $windowA;

    private ServiceWindow $windowB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        // unstaffed(): these windows are asserted to be genuinely empty - no
        // services and no staff - which is what makes them deletable. The
        // factory staffs a window by default (decision D2), and an assigned
        // clerk is a dependency that would block the deletion.
        $this->windowA = ServiceWindow::factory()->unstaffed()->create(['code' => 'WINDOW_A', 'name' => 'Window A']);
        $this->windowB = ServiceWindow::factory()->unstaffed()->create(['code' => 'WINDOW_B', 'name' => 'Window B']);
    }

    /* ------------------------------------------------------------------ */
    /* Access control */
    /* ------------------------------------------------------------------ */

    public function test_a_client_cannot_reach_any_admin_screen(): void
    {
        $paths = [
            '/admin', '/admin/windows', '/admin/services', '/admin/slots',
            '/admin/holidays', '/admin/staff', '/admin/audit',
        ];

        foreach ($paths as $path) {
            $this->actingAs(User::factory()->client()->create())
                ->get($path)
                ->assertForbidden();
        }
    }

    public function test_a_client_cannot_mutate_configuration(): void
    {
        $client = User::factory()->client()->create();

        $this->actingAs($client)
            ->post('/admin/windows', [
                'name' => 'Sneaky', 'code' => 'SNEAKY', 'avg_service_minutes' => 10,
            ])->assertForbidden();

        $this->actingAs($client)
            ->post('/admin/holidays', [
                'date' => today()->toDateString(), 'name' => 'Nope',
            ])->assertForbidden();

        $this->assertDatabaseCount('service_windows', 2);
    }

    public function test_staff_cannot_reach_the_admin_area(): void
    {
        // A regression guard: forgetting the admin middleware once exposed the
        // notification log, with client emails and phones, to every staff member.
        $this->actingAs(User::factory()->staff()->create(['service_window_id' => $this->windowA->id]))
            ->get('/admin')
            ->assertForbidden();

        $this->actingAs(User::factory()->staff()->create(['service_window_id' => $this->windowA->id]))
            ->get('/admin/audit')
            ->assertForbidden();
    }

    public function test_a_deactivated_administrator_cannot_sign_in(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'gone@govqueue.test',
            'is_active' => false,
        ]);

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /* Dashboard */
    /* ------------------------------------------------------------------ */

    public function test_the_dashboard_loads(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('Administration');
    }

    public function test_the_dashboard_flags_a_service_with_no_bookable_slots(): void
    {
        $orphan = Service::factory()->create([
            'service_window_id' => $this->windowA->id,
            'name' => 'Unschedulable Service',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Unschedulable Service')
            ->assertSee('has no bookable slots');
    }

    public function test_the_dashboard_is_quiet_when_everything_is_configured(): void
    {
        $service = Service::factory()->create([
            'service_window_id' => $this->windowA->id,
            'is_active' => true,
        ]);

        SlotTemplate::create([
            'service_id' => $service->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'slot_minutes' => 30,
            'capacity' => 5,
            'effective_from' => today()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('has no bookable slots');
    }

    /* ------------------------------------------------------------------ */
    /* Windows */
    /* ------------------------------------------------------------------ */

    public function test_an_administrator_can_create_a_window(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/windows', [
                'name' => 'Window C - Licensing',
                'code' => 'WINDOW_C',
                'avg_service_minutes' => 20,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.windows.index'));

        $this->assertDatabaseHas('service_windows', ['code' => 'WINDOW_C', 'is_active' => true]);
    }

    public function test_a_window_code_must_be_url_safe(): void
    {
        // The code is the public route key, so a lowercase or spaced code would
        // produce a URL that cannot be linked to.
        $this->actingAs($this->admin)
            ->post('/admin/windows', ['name' => 'Bad', 'code' => 'window one', 'avg_service_minutes' => 10])
            ->assertSessionHasErrors('code');

        $this->actingAs($this->admin)
            ->post('/admin/windows', ['name' => 'Bad', 'code' => 'lower', 'avg_service_minutes' => 10])
            ->assertSessionHasErrors('code');
    }

    public function test_a_duplicate_window_code_is_a_validation_error_not_a_500(): void
    {
        // service_windows.code is UNIQUE, so without the rule this surfaces as an
        // integrity-constraint 500.
        $this->actingAs($this->admin)
            ->post('/admin/windows', ['name' => 'Clash', 'code' => 'WINDOW_A', 'avg_service_minutes' => 10])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, ServiceWindow::where('code', 'WINDOW_A')->count());
    }

    public function test_a_window_can_be_saved_without_changing_its_own_code(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/windows/'.$this->windowA->code, [
                'name' => 'Renamed Window A',
                'code' => 'WINDOW_A',
                'avg_service_minutes' => 25,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.windows.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Window A', $this->windowA->fresh()->name);
    }

    public function test_a_duplicate_service_code_is_a_validation_error_not_a_500(): void
    {
        // services.code is UNIQUE, with the same consequence.
        $existing = Service::factory()->create([
            'service_window_id' => $this->windowA->id,
            'code' => 'TAKEN-CODE',
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/services', [
                'service_window_id' => $this->windowB->id,
                'name' => 'Clashing Service',
                'code' => 'TAKEN-CODE',
                'fee' => 0,
                'duration_minutes' => 15,
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Service::where('code', 'TAKEN-CODE')->count());
    }

    public function test_a_service_can_be_saved_without_changing_its_own_code(): void
    {
        $service = Service::factory()->create([
            'service_window_id' => $this->windowA->id,
            'code' => 'KEEP-ME',
        ]);

        $this->actingAs($this->admin)
            ->put('/admin/services/'.$service->code, [
                'service_window_id' => $this->windowA->id,
                'name' => 'Renamed Service',
                'code' => 'KEEP-ME',
                'fee' => 25,
                'duration_minutes' => 20,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.services.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Service', $service->fresh()->name);
    }

    public function test_a_window_with_services_cannot_be_deleted(): void
    {
        Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $this->actingAs($this->admin)
            ->from(route('admin.windows.index'))
            ->delete('/admin/windows/'.$this->windowA->code)
            ->assertRedirect(route('admin.windows.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('service_windows', ['code' => 'WINDOW_A']);
    }

    public function test_a_window_with_staff_cannot_be_deleted(): void
    {
        User::factory()->staff()->create(['service_window_id' => $this->windowA->id]);

        $this->actingAs($this->admin)
            ->from(route('admin.windows.index'))
            ->delete('/admin/windows/'.$this->windowA->code)
            ->assertSessionHas('error');

        $this->assertDatabaseHas('service_windows', ['code' => 'WINDOW_A']);
    }

    public function test_an_empty_window_can_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete('/admin/windows/'.$this->windowA->code)
            ->assertRedirect(route('admin.windows.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('service_windows', ['code' => 'WINDOW_A']);
    }

    /* ------------------------------------------------------------------ */
    /* Services */
    /* ------------------------------------------------------------------ */

    public function test_an_administrator_can_create_a_service(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/services', [
                'service_window_id' => $this->windowA->id,
                'name' => 'Barangay Clearance',
                'code' => 'BRGY-CLR',
                'fee' => 50,
                'duration_minutes' => 20,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('services', ['code' => 'BRGY-CLR']);
    }

    public function test_a_service_with_appointments_cannot_be_deleted(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        Appointment::factory()->create([
            'service_id' => $service->id,
            'status' => 'completed',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.services.index'))
            ->delete('/admin/services/'.$service->code)
            ->assertRedirect(route('admin.services.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('services', ['code' => $service->code]);
    }

    public function test_a_service_still_offering_bookable_slots_cannot_be_deleted(): void
    {
        // No appointments yet, but slots a client could still grab. Deleting would
        // let a client book a service that has just vanished.
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $this->windowA->id,
            'slot_date' => today()->addDay()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => today()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => today()->addDay()->setTime(9, 30)->toDateTimeString(),
            'capacity' => 4,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.services.index'))
            ->delete('/admin/services/'.$service->code)
            ->assertSessionHas('error');

        $this->assertDatabaseHas('services', ['code' => $service->code]);
    }

    public function test_a_service_with_no_appointments_and_no_future_slots_can_be_deleted(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $this->actingAs($this->admin)
            ->delete('/admin/services/'.$service->code)
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('services', ['code' => $service->code]);
    }

    /**
     * The window-move side effect.
     *
     * time_slots.service_window_id and appointments.service_window_id are what the
     * staff console and the lobby display actually group by. A service moved to a
     * new window but whose slots stay behind would keep appearing on the old
     * window's board - a queue nobody is working.
     */
    public function test_moving_a_service_to_another_window_carries_its_slots_and_upcoming_bookings(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $slot = TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $this->windowA->id,
            'slot_date' => today()->addDay()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => today()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => today()->addDay()->setTime(9, 30)->toDateTimeString(),
            'capacity' => 4,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        $upcoming = Appointment::factory()->create([
            'service_id' => $service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->windowA->id,
            'status' => 'confirmed',
        ]);

        $this->actingAs($this->admin)
            ->put('/admin/services/'.$service->code, [
                'service_window_id' => $this->windowB->id,
                'name' => $service->name,
                'code' => $service->code,
                'fee' => $service->fee,
                'duration_minutes' => $service->duration_minutes,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.services.index'));

        $this->assertSame(
            (int) $this->windowB->id,
            (int) $service->fresh()->service_window_id
        );
        $this->assertSame((int) $this->windowB->id, (int) $slot->fresh()->service_window_id);
        $this->assertSame((int) $this->windowB->id, (int) $upcoming->fresh()->service_window_id);
    }

    public function test_completed_appointments_keep_the_window_they_were_served_at(): void
    {
        // Rewriting history would put a finished transaction on a window it was
        // never actually served at.
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $slot = TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $this->windowA->id,
            'slot_date' => today()->subDay()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => today()->subDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => today()->subDay()->setTime(9, 30)->toDateTimeString(),
            'capacity' => 4,
            'booked_count' => 1,
            'is_open' => false,
        ]);

        $finished = Appointment::factory()->create([
            'service_id' => $service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->windowA->id,
            'status' => 'completed',
        ]);

        $this->actingAs($this->admin)->put('/admin/services/'.$service->code, [
            'service_window_id' => $this->windowB->id,
            'name' => $service->name,
            'code' => $service->code,
            'fee' => $service->fee,
            'duration_minutes' => $service->duration_minutes,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame(
            (int) $this->windowA->id,
            (int) $finished->fresh()->service_window_id,
            'A completed appointment must keep the window it was served at.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Slot templates */
    /* ------------------------------------------------------------------ */

    private function templatePayload(Service $service, array $overrides = []): array
    {
        return array_merge([
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_minutes' => 30,
            'capacity' => 5,
            'effective_from' => today()->toDateString(),
            'is_active' => '1',
        ], $overrides);
    }

    public function test_an_administrator_can_add_a_template(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $this->actingAs($this->admin)
            ->post("/admin/services/{$service->code}/templates", $this->templatePayload($service))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('slot_templates', ['service_id' => $service->id, 'capacity' => 5]);
    }

    public function test_a_template_accepts_seconds_precision_times_and_stores_hh_mm(): void
    {
        /*
         * A TIME column hands back "08:00:00". <input type="time"> is specified in
         * HH:MM and submits an empty value for anything else, which then failed
         * the HH:MM validation on every save - an error the administrator had
         * never caused and could not fix from the message. Both shapes are
         * accepted, and stored as HH:MM so the column carries one shape.
         */
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $this->actingAs($this->admin)
            ->post("/admin/services/{$service->code}/templates", $this->templatePayload($service, [
                'start_time' => '08:00:00',
                'end_time' => '12:00:00',
            ]))
            ->assertSessionHas('success');

        $template = SlotTemplate::query()->sole();

        // The column is TIME, so MySQL always renders seconds. What matters is
        // that the accepted value is the time that was asked for, not that the
        // column was narrowed - and that both shapes reach it unchanged.
        $this->assertSame('08:00:00', $template->start_time);
        $this->assertSame('12:00:00', $template->end_time);
    }

    public function test_a_template_with_an_unreadable_time_says_how_to_fix_it(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.templates.index', $service))
            ->post("/admin/services/{$service->code}/templates", $this->templatePayload($service, [
                'start_time' => '8am',
            ]));

        $response->assertSessionHasErrors('start_time');

        // The message has to name the field and an example. "Use a 24-hour
        // time" on its own left an administrator hunting for a setting that
        // did not exist.
        $this->assertStringContainsString(
            '24-hour',
            (string) session('errors')->first('start_time'),
        );
    }

    /**
     * The rule says what day it is, in the day the administrator chose.
     *
     * The summary sentence worked the day name out from a base Sunday with
     * startOfWeek() in the middle - and startOfWeek() starts the week on
     * Monday, so every name came out one day late. A rule for Monday was
     * described as a Tuesday, and the rule was still stored and generated
     * correctly, which is the worst kind of bug: the screen disagreed with
     * the configuration while the configuration was right.
     *
     * Every day is checked, because a shift of one is only visible on the
     * days that moved.
     */
    public function test_a_template_says_the_day_it_was_set_for(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        // Monday to Friday are the office's open days, and the days an
        // administrator actually chooses between.
        $days = [
            1 => 'Mondays',
            2 => 'Tuesdays',
            3 => 'Wednesdays',
            4 => 'Thursdays',
            5 => 'Fridays',
        ];

        foreach ($days as $dayOfWeek => $expected) {
            $this->actingAs($this->admin)
                ->post("/admin/services/{$service->code}/templates", $this->templatePayload($service, [
                    'day_of_week' => $dayOfWeek,
                ]))
                ->assertSessionHas('success');
        }

        $html = $this->actingAs($this->admin)
            ->get(route('admin.templates.index', $service))
            ->assertOk()
            ->getContent();

        foreach ($days as $expected) {
            $this->assertStringContainsString(
                $expected,
                $html,
                'A rule for '.$expected.' is not described as '.$expected.'.',
            );
        }
    }

    public function test_a_template_whose_end_is_before_its_start_is_refused(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $this->actingAs($this->admin)
            ->from(route('admin.templates.index', $service))
            ->post("/admin/services/{$service->code}/templates", $this->templatePayload($service, [
                'start_time' => '12:00',
                'end_time' => '08:00',
            ]))
            ->assertRedirect(route('admin.templates.index', $service))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('slot_templates', 0);
    }

    public function test_a_template_whose_slot_does_not_fit_the_window_is_refused(): void
    {
        // The generator drops any slot that would overrun the window, so a 30
        // minute slot in a 20 minute window yields nothing at all and the service
        // silently has no availability.
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $this->actingAs($this->admin)
            ->from(route('admin.templates.index', $service))
            ->post("/admin/services/{$service->code}/templates", $this->templatePayload($service, [
                'start_time' => '09:00',
                'end_time' => '09:20',
                'slot_minutes' => 30,
            ]))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('slot_templates', 0);
    }

    public function test_a_template_with_inverted_effective_dates_is_refused(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $this->actingAs($this->admin)
            ->from(route('admin.templates.index', $service))
            ->post("/admin/services/{$service->code}/templates", $this->templatePayload($service, [
                'effective_from' => today()->toDateString(),
                'effective_to' => today()->subWeek()->toDateString(),
            ]))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('slot_templates', 0);
    }

    public function test_a_template_can_be_deactivated_and_removed(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $template = SlotTemplate::create([
            'service_id' => $service->id,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'slot_minutes' => 30,
            'capacity' => 5,
            'effective_from' => today()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.templates.index', $service))
            ->put("/admin/templates/{$template->id}", $this->templatePayload($service, ['is_active' => '0']))
            ->assertSessionHas('success');

        $this->assertFalse((bool) $template->fresh()->is_active);

        $this->actingAs($this->admin)
            ->from(route('admin.templates.index', $service))
            ->delete("/admin/templates/{$template->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('slot_templates', ['id' => $template->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Slot capacity overrides */
    /* ------------------------------------------------------------------ */

    /**
     * A slot the administrator may still change.
     *
     * Defaults to tomorrow at 09:00, so `end_at` is in the future and the slot
     * is editable. Tests that care about a finished slot pass their own times.
     */
    private function slot(array $overrides = []): TimeSlot
    {
        $when = $overrides['slot_date'] ?? today()->addDay()->toDateString();

        return TimeSlot::create(array_merge([
            'service_id' => Service::factory()->create(['service_window_id' => $this->windowA->id])->id,
            'service_window_id' => $this->windowA->id,
            'slot_date' => $when,
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => today()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => today()->addDay()->setTime(9, 30)->toDateTimeString(),
            'capacity' => 4,
            'booked_count' => 0,
            'is_open' => true,
        ], $overrides));
    }

    /**
     * A slot from earlier today, so it has already finished.
     *
     * Used to prove the read-only rule. Deliberately built from real datetimes
     * rather than a past date, because a slot on a past *day* would also be
     * blocked by the day-level rule and the test would pass for the wrong
     * reason.
     */
    private function finishedSlot(array $overrides = []): TimeSlot
    {
        /*
         * Yesterday, not 06:00 today.
         *
         * This was pinned to 06:00-06:30 on today's date, which makes it a
         * finished slot only between 06:30 and midnight. Run before half past
         * six, the slot was still in the future, the office was correctly
         * allowed to edit it, and four tests failed - with no code change
         * anywhere in the project.
         *
         * Derived from now() and pushed into the past, so "finished" means
         * finished whatever time the suite happens to run.
         */
        $start = now()->subHours(3);

        return $this->slot(array_merge([
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
        ], $overrides));
    }

    public function test_capacity_can_be_raised(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'capacity', 'capacity' => 9])
            ->assertSessionHas('success');

        $this->assertSame(9, $slot->fresh()->capacity);
    }

    public function test_saving_capacity_leaves_the_open_state_alone(): void
    {
        /*
         * The capacity form and the open/closed toggle are two separate forms per
         * row and neither submits both fields. Inferring which one was used from
         * the missing field broke in both directions: reading a missing is_open
         * as "false" silently closed a slot when saving its capacity, and
         * reading it as "keep the current state" made it impossible to close a
         * slot at all - because an unticked checkbox is not submitted.
         *
         * The forms now declare themselves with `intent`, which is what makes
         * both directions safe.
         */
        $slot = $this->slot(['is_open' => true]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'capacity', 'capacity' => 9])
            ->assertSessionHas('success');

        $this->assertSame(9, $slot->fresh()->capacity);
        $this->assertTrue($slot->fresh()->is_open);
    }

    public function test_saving_capacity_leaves_a_closed_slot_closed(): void
    {
        $slot = $this->slot(['is_open' => false]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'capacity', 'capacity' => 9])
            ->assertSessionHas('success');

        $this->assertFalse($slot->fresh()->is_open);
    }

    public function test_a_slot_can_be_closed(): void
    {
        $slot = $this->slot(['is_open' => true]);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'open_state']);

        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $this->assertFalse($slot->fresh()->is_open);
    }

    /*
     * The regression this guards is one no HTTP test can see on its own.
     *
     * resources/js/app.js disables a form's submit controls on the submit event
     * as a double-post guard. It also ran for submissions that were then
     * cancelled - every confirmation dialog returns false - and left the button
     * disabled forever. Click, cancel, and that button was dead for the rest of
     * the page's life.
     *
     * The visible consequences were reported as unrelated bugs: "the delete
     * confirmation does not appear" (it appeared once, then the button died) and
     * "removing a slot leaves the button disabled" (the same thing, noticed).
     * Both screens were correct in HTML; the fault was entirely in the script.
     *
     * These assertions cover the markup the script depends on - one shared
     * confirm() on every destructive form - so a regression that removed the
     * dialogs would fail here rather than in a browser.
     */

    public function test_every_destructive_admin_form_asks_for_confirmation(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        SlotTemplate::create([
            'service_id' => $service->id,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'slot_minutes' => 30,
            'capacity' => 5,
            'effective_from' => today()->toDateString(),
            'is_active' => true,
        ]);

        // Every screen needs something to delete, or it renders an empty table
        // and the assertion passes for the wrong reason.
        $holiday = Holiday::create([
            'date' => today()->addWeek()->toDateString(),
            'name' => 'Test Closure',
        ]);

        // Runs to the end of today, so the screen shows it as editable and the
        // Remove button is actually rendered. A tomorrow-dated slot would leave
        // today's screen empty and the assertion would pass for the wrong reason.
        $removable = $this->slot([
            'booked_count' => 0,
            'slot_date' => today()->toDateString(),
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'start_at' => today()->startOfDay()->toDateTimeString(),
            'end_at' => today()->setTime(23, 59)->toDateTimeString(),
        ]);

        $deletableStaff = User::factory()->staff()->create([
            'service_window_id' => $this->windowA->id,
            'email' => 'deletable@govqueue.test',
        ]);

        $template = $service->slotTemplates()->first();

        // Each screen, paired with a delete action it must be offering.
        $pages = [
            route('admin.slots.index') => route('admin.slots.destroy', $removable),
            route('admin.windows.index') => route('admin.windows.destroy', $this->windowB),
            route('admin.services.index') => route('admin.services.destroy', $service),
            route('admin.staff.index') => route('admin.staff.destroy', $deletableStaff),
            route('admin.holidays.index') => route('admin.holidays.destroy', $holiday),
            route('admin.templates.index', $service) => route('admin.templates.destroy', $template),
        ];

        foreach ($pages as $page => $destroyUrl) {
            $content = $this->actingAs($this->admin)->get($page)->assertOk()->getContent();

            // Each form that destroys something must route through the shared
            // dialog, which is the only thing the submit guard knows how to
            // back out of.
            $this->assertStringContainsString(
                'window.govqueueConfirm',
                $content,
                "{$page} has a destructive form with no confirmation, which leaves its "
                .'button permanently disabled after the first cancel.'
            );

            // And must keep a fallback, so the action still asks something when
            // the script has not loaded.
            $this->assertStringContainsString(
                ': confirm(',
                $content,
                "{$page} has no fallback confirmation for visitors without JavaScript."
            );

            /*
             * Guard against a vacuous pass.
             *
             * Asserting "the page contains a confirm" is satisfied by a page that
             * rendered no destructive form at all - which is exactly what an empty
             * table looks like. Each page is therefore paired with the URL of a
             * delete action it must actually be offering, so this cannot pass by
             * having nothing to confirm.
             */
            $this->assertStringContainsString(
                $destroyUrl,
                $content,
                "{$page} rendered no delete action, so its confirmation cannot have been checked."
            );
        }
    }

    public function test_a_closed_slot_can_be_opened_again(): void
    {
        // The counterpart: the fix must not have become "a slot can never change".
        $slot = $this->slot(['is_open' => false]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'open_state', 'is_open' => '1'])
            ->assertSessionHas('success');

        $this->assertTrue($slot->fresh()->is_open);
    }

    public function test_the_toggle_cannot_also_change_capacity(): void
    {
        /*
         * The toggle form used to carry a hidden capacity so that the endpoint's
         * "required" rule would pass. That was the same defect in mirror image:
         * a field the form did not mean to change, silently authoritative.
         */
        $slot = $this->slot(['capacity' => 7, 'is_open' => true]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'open_state', 'capacity' => 99, 'is_open' => '0'])
            ->assertSessionHas('success');

        $this->assertSame(7, $slot->fresh()->capacity);
    }

    public function test_an_unrecognised_form_changes_nothing(): void
    {
        // Rather than guessing which of two actions was meant, an unrecognised
        // shape is refused. Both previous guesses were wrong in one direction
        // each, which is the argument for not guessing at all.
        $slot = $this->slot(['capacity' => 4, 'is_open' => true]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['capacity' => 9, 'is_open' => '1'])
            ->assertSessionHas('error');

        $this->assertSame(4, $slot->fresh()->capacity);
        $this->assertTrue($slot->fresh()->is_open);
    }

    public function test_capacity_cannot_drop_below_the_clients_already_booked(): void
    {
        // Below booked_count the slot is permanently over-subscribed: isBookable()
        // goes false for everyone and remainingCapacity() goes negative, so the
        // clients already booked would vanish from the slot they hold.
        $slot = $this->slot(['capacity' => 4, 'booked_count' => 3]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'capacity', 'capacity' => 2])
            ->assertSessionHas('error');

        $this->assertSame(4, $slot->fresh()->capacity);
    }

    public function test_capacity_may_equal_the_number_booked(): void
    {
        $slot = $this->slot(['capacity' => 4, 'booked_count' => 3]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'capacity', 'capacity' => 3])
            ->assertSessionHas('success');

        $this->assertSame(3, $slot->fresh()->capacity);
    }

    public function test_a_slot_cannot_be_left_open_with_zero_capacity(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'capacity', 'capacity' => 0])
            ->assertSessionHas('error');

        $this->assertTrue((bool) $slot->fresh()->is_open);
    }

    public function test_a_slot_with_clients_booked_cannot_be_deleted(): void
    {
        $slot = $this->slot(['booked_count' => 1]);

        Appointment::factory()->create([
            'time_slot_id' => $slot->id,
            'service_id' => $slot->service_id,
            'service_window_id' => $slot->service_window_id,
            'status' => 'confirmed',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->delete("/admin/slots/{$slot->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('time_slots', ['id' => $slot->id]);
    }

    public function test_an_empty_slot_can_be_deleted(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->delete("/admin/slots/{$slot->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('time_slots', ['id' => $slot->id]);
    }

    public function test_bulk_update_skips_slots_that_have_clients_booked(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);
        $date = today()->addDay()->toDateString();

        $free = TimeSlot::create([
            'service_id' => $service->id, 'service_window_id' => $this->windowA->id,
            'slot_date' => $date, 'start_time' => '08:00:00', 'end_time' => '08:30:00',
            'start_at' => $date.' 08:00:00', 'end_at' => $date.' 08:30:00',
            'capacity' => 4, 'booked_count' => 0, 'is_open' => true,
        ]);

        $busy = TimeSlot::create([
            'service_id' => $service->id, 'service_window_id' => $this->windowA->id,
            'slot_date' => $date, 'start_time' => '09:00:00', 'end_time' => '09:30:00',
            'start_at' => $date.' 09:00:00', 'end_at' => $date.' 09:30:00',
            'capacity' => 4, 'booked_count' => 3, 'is_open' => true,
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put('/admin/slots/bulk', [
                'service_id' => $service->id,
                'date' => $date,
                'capacity' => 1,
            ])
            ->assertSessionHas('success')
            ->assertSessionHas('error');

        // The free slot took the new capacity; the booked one was left alone.
        $this->assertSame(1, $free->fresh()->capacity);
        $this->assertSame(4, $busy->fresh()->capacity);
    }

    /* ------------------------------------------------------------------ */
    /* Holidays */
    /* ------------------------------------------------------------------ */

    public function test_an_administrator_can_declare_a_holiday(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/holidays', ['date' => '2026-12-25', 'name' => 'Christmas Day'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('holidays', ['name' => 'Christmas Day']);
    }

    public function test_a_holiday_declared_for_today_still_reports_affected_clients(): void
    {
        /*
         * The same trap that was fixed in TimeSlotController, missed here.
         *
         * holidays.date is cast to a date, so it materialises as midnight - and
         * midnight today is already in the past by lunchtime. The screen skipped
         * counting affected clients for any holiday whose date had "passed", so
         * declaring the office closed for TODAY reported that nobody was
         * affected - on the one screen whose entire purpose is to answer "which
         * clients need rebooking?".
         *
         * A closure for the current day is a realistic thing to declare: an
         * administrator finds out first thing that the building is unusable.
         */
        $today = today();

        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $slot = TimeSlot::create([
            'service_id' => $service->id, 'service_window_id' => $this->windowA->id,
            'slot_date' => $today->toDateString(), 'start_time' => '09:00:00', 'end_time' => '09:30:00',
            'start_at' => $today->setTime(9, 0)->toDateTimeString(),
            'end_at' => $today->setTime(9, 30)->toDateTimeString(),
            'capacity' => 4, 'booked_count' => 1, 'is_open' => true,
        ]);

        Appointment::factory()->create([
            'time_slot_id' => $slot->id,
            'service_id' => $service->id,
            'service_window_id' => $this->windowA->id,
            'status' => 'confirmed',
            'scheduled_at' => $today->setTime(9, 0),
            'queue_date' => $today->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/holidays', ['date' => $today->toDateString(), 'name' => 'Closure Today'])
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->get('/admin/holidays')
            ->assertOk()
            ->assertSee('Closure Today')
            ->assertSee('still hold a booking');
    }

    public function test_a_holiday_in_the_past_is_still_listed(): void
    {
        // Skipping the client count for a past date is correct - the rebooking
        // moment has passed - but the closure itself must stay on the list, or
        // the office loses the record that the day was ever closed.
        //
        // It is no longer on the *default* view: past dates cannot affect a
        // booking, so listing them first buried the rows that need a decision.
        // The record is kept in the database and shown on request instead.
        Holiday::create([
            'date' => today()->subWeek()->toDateString(),
            'name' => 'Last Week Closure',
        ]);

        // Off by default, with an offer to reveal it.
        $this->actingAs($this->admin)
            ->get('/admin/holidays')
            ->assertOk()
            ->assertDontSee('Last Week Closure')
            ->assertSee('Show 1 past closure');

        // Still there, and still readable, one click away.
        $this->actingAs($this->admin)
            ->get('/admin/holidays?show_past=1')
            ->assertOk()
            ->assertSee('Last Week Closure');

        $this->assertNotNull(Holiday::where('date', today()->subWeek()->toDateString())->first());
    }

    public function test_a_holiday_in_the_past_cannot_be_newly_declared(): void
    {
        // Guard against the pile-up the default-view change is meant to prevent:
        // backdating a closure can never hide a slot, so it is refused outright.
        $this->actingAs($this->admin)
            ->from('/admin/holidays')
            ->post('/admin/holidays', [
                'date' => today()->subWeek()->toDateString(),
                'name' => 'Backdated Closure',
            ])
            ->assertSessionHasErrors('date');

        $this->assertSame(0, Holiday::where('name', 'Backdated Closure')->count());
    }

    public function test_a_duplicate_holiday_is_a_validation_error_not_a_500(): void
    {
        // holidays.date is UNIQUE, so without the rule this surfaces as an
        // integrity-constraint 500.
        Holiday::create(['date' => '2026-12-25', 'name' => 'Christmas Day']);

        $this->actingAs($this->admin)
            ->post('/admin/holidays', ['date' => '2026-12-25', 'name' => 'Again'])
            ->assertSessionHasErrors('date');

        $this->assertSame(1, Holiday::whereDate('date', '2026-12-25')->count());
    }

    public function test_declaring_a_holiday_warns_about_clients_already_booked(): void
    {
        $date = today()->addDays(3);

        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $slot = TimeSlot::create([
            'service_id' => $service->id, 'service_window_id' => $this->windowA->id,
            'slot_date' => $date->toDateString(), 'start_time' => '09:00:00', 'end_time' => '09:30:00',
            'start_at' => $date->setTime(9, 0)->toDateTimeString(), 'end_at' => $date->setTime(9, 30)->toDateTimeString(),
            'capacity' => 4, 'booked_count' => 1, 'is_open' => true,
        ]);

        Appointment::factory()->create([
            'time_slot_id' => $slot->id,
            'service_id' => $service->id,
            'service_window_id' => $this->windowA->id,
            'status' => 'confirmed',
            'scheduled_at' => $date->setTime(9, 0),
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/holidays', ['date' => $date->toDateString(), 'name' => 'Probe Closure'])
            ->assertSessionHas('success');

        $response = $this->actingAs($this->admin)->get('/admin/holidays');

        // The client must be named, and the booking must still exist: a date
        // field cancelling a client's appointment silently is not acceptable.
        $response->assertOk()->assertSee('Probe Closure')->assertSee('still hold a booking');
        $this->assertDatabaseHas('appointments', ['id' => $slot->appointments()->value('id')]);
    }

    public function test_a_holiday_can_be_removed(): void
    {
        // Relative, not a fixed Christmas: past closures are deliberately not
        // removable, so a hardcoded date would quietly turn this into a test
        // for the refusal once that day had gone by.
        $holiday = Holiday::create([
            'date' => today()->addMonths(3)->toDateString(), 'name' => 'Christmas Day',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.holidays.index'))
            ->delete("/admin/holidays/{$holiday->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('holidays', ['id' => $holiday->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Audit trail */
    /* ------------------------------------------------------------------ */

    public function test_configuration_changes_are_audited(): void
    {
        $this->actingAs($this->admin)->post('/admin/windows', [
            'name' => 'Audited Window', 'code' => 'AUDITED', 'avg_service_minutes' => 15, 'is_active' => '1',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'window.created',
            'user_id' => $this->admin->id,
        ]);
    }

    /**
     * A refused action is recorded, not just a successful one.
     *
     * An audit trail showing only successes reads as an all-clear. Repeated
     * attempts to delete a booked service, or to disable the last administrator,
     * are the patterns worth surfacing, so the refusal itself is an audit entry.
     */
    public function test_a_blocked_action_is_audited_too(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);
        Appointment::factory()->create(['service_id' => $service->id, 'status' => 'completed']);

        $this->actingAs($this->admin)->from(route('admin.services.index'))
            ->delete('/admin/services/'.$service->code);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'service.delete_blocked',
            'subject_type' => 'Service',
            'subject_id' => $service->id,
            'user_id' => $this->admin->id,
        ]);

        // And the success that did not happen must not be recorded.
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'service.deleted',
            'subject_id' => $service->id,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Finished slots are read-only */
    /* ------------------------------------------------------------------ */

    public function test_a_finished_slot_cannot_have_its_capacity_changed(): void
    {
        /*
         * A finished slot's capacity is the denominator of the utilisation
         * figure in Reports. Editing it restates what the office actually
         * offered that morning, which is the one thing an administrative screen
         * must not be able to do.
         */
        $slot = $this->finishedSlot(['capacity' => 5]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'capacity', 'capacity' => 50])
            ->assertSessionHas('error');

        $this->assertSame(5, $slot->fresh()->capacity);
    }

    public function test_a_finished_slot_cannot_be_closed_or_reopened(): void
    {
        $slot = $this->finishedSlot(['is_open' => true]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'open_state'])
            ->assertSessionHas('error');

        $this->assertTrue($slot->fresh()->is_open);
    }

    public function test_a_finished_slot_cannot_be_deleted(): void
    {
        // Even an empty one. Removing it would leave a hole in the day's record,
        // and Reports counts places offered - a missing slot is not zero
        // capacity, it is absent data.
        $slot = $this->finishedSlot();

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->delete("/admin/slots/{$slot->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('time_slots', ['id' => $slot->id]);
    }

    public function test_a_slot_still_running_can_still_be_changed(): void
    {
        /*
         * The boundary is end_at, not slot_date. An administrator adding a
         * second clerk mid-morning has to be able to raise capacity while the
         * slot is being served, or the read-only rule would make the ordinary
         * case of the day impossible.
         */
        $slot = $this->slot([
            'slot_date' => today()->toDateString(),
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'start_at' => today()->startOfDay()->toDateTimeString(),
            'end_at' => today()->setTime(23, 59)->toDateTimeString(),
            'capacity' => 4,
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'capacity', 'capacity' => 12])
            ->assertSessionHas('success');

        $this->assertSame(12, $slot->fresh()->capacity);
    }

    public function test_a_past_day_is_viewable_but_edits_are_refused(): void
    {
        // The day-level rule, distinct from the per-slot one: even a slot with a
        // future end time on a past date is not editable.
        $slot = $this->slot(['slot_date' => today()->subDay()->toDateString()]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'capacity', 'capacity' => 30])
            ->assertSessionHas('error');

        $this->assertSame(4, $slot->fresh()->capacity);
    }

    public function test_a_past_day_is_still_shown_and_says_why_it_is_read_only(): void
    {
        $this->slot(['slot_date' => today()->subDay()->toDateString()]);

        $this->actingAs($this->admin)
            ->get('/admin/slots?date='.today()->subDay()->toDateString())
            ->assertOk()
            // The day is rendered, because reporting and reconciling need it.
            ->assertSee('view only', escape: false)
            ->assertSee('utilisation figures in Reports', escape: false)
            // And it offers no way to change anything: no editing form, no
            // toggle, no remove. Asserted as markup because "the button is
            // missing" is a different fix from "the button is disabled".
            ->assertDontSee('name="intent"', escape: false)
            ->assertDontSee(route('admin.slots.destroy', ['slot' => 1]), escape: false);
    }

    public function test_the_date_picker_cannot_be_walked_back_past_the_data(): void
    {
        $this->slot(['slot_date' => today()->subDays(3)->toDateString()]);

        $this->actingAs($this->admin)
            ->get('/admin/slots?date=2001-01-01')
            ->assertOk()
            // Clamped to today rather than refused: a hand-edited ?date= is a
            // typo, and an error page helps nobody reconcile a busy day.
            ->assertSee(today()->toDateString(), escape: false);

        $this->actingAs($this->admin)
            ->get('/admin/slots?date='.today()->subDays(3)->toDateString())
            ->assertOk()
            ->assertSee(today()->subDays(3)->toDateString(), escape: false);
    }

    public function test_the_date_picker_offers_a_today_shortcut(): void
    {
        $this->slot(['slot_date' => today()->subDay()->toDateString()]);

        // Without it, the only way back to today from a past day is clicking
        // the arrow one day at a time.
        $this->actingAs($this->admin)
            ->get('/admin/slots?date='.today()->subDay()->toDateString())
            ->assertOk()
            ->assertSee(route('admin.slots.index'), escape: false)
            ->assertSee('Today', escape: false);
    }

    public function test_bulk_update_leaves_finished_slots_alone(): void
    {
        /*
         * A bulk action must not be able to do what the single-row form refuses.
         * This is the same class of hole as the hidden capacity field: the
         * per-slot guard is only real if the bulk path respects it too.
         */
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);
        $today = today()->toDateString();

        $finished = $this->finishedSlot([
            'service_id' => $service->id,
            'capacity' => 5,
        ]);

        // Runs to the end of today, so it is genuinely still live whatever hour
        // the suite happens to run. A fixed 10:00 slot would silently become a
        // finished slot after lunchtime and the test would pass for the wrong
        // reason - or fail, depending on the clock.
        $running = $this->slot([
            'service_id' => $service->id,
            'slot_date' => $today,
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'start_at' => today()->startOfDay()->toDateTimeString(),
            'end_at' => today()->setTime(23, 59)->toDateTimeString(),
            'capacity' => 4,
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put('/admin/slots/bulk', [
                'service_id' => $service->id,
                'date' => $today,
                'capacity' => 2,
            ])
            ->assertSessionHas('success');

        $this->assertSame(5, $finished->fresh()->capacity);
        $this->assertSame(2, $running->fresh()->capacity);
    }

    public function test_a_refused_capacity_change_is_audited(): void
    {
        $slot = $this->slot(['capacity' => 4, 'booked_count' => 3]);

        $this->actingAs($this->admin)->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['intent' => 'capacity', 'capacity' => 1]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'time_slot.capacity_blocked',
            'subject_type' => 'TimeSlot',
            'subject_id' => $slot->id,
        ]);
    }

    public function test_a_refused_template_is_audited(): void
    {
        $service = Service::factory()->create(['service_window_id' => $this->windowA->id]);

        $this->actingAs($this->admin)->from(route('admin.templates.index', $service))
            ->post("/admin/services/{$service->code}/templates", $this->templatePayload($service, [
                'start_time' => '12:00',
                'end_time' => '08:00',
            ]));

        $this->assertDatabaseHas('audit_logs', ['action' => 'slot_template.rejected']);
    }

    public function test_the_audit_screen_loads_and_filters(): void
    {
        $this->actingAs($this->admin)->get('/admin/audit')->assertOk();

        $this->actingAs($this->admin)
            ->get('/admin/audit?action=window.')
            ->assertOk()
            ->assertViewHas('logs');
    }

    public function test_the_audit_screen_survives_a_wildcard_action_filter(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/audit?action=%25')
            ->assertOk();
    }
}
