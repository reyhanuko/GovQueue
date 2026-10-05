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

        $this->windowA = ServiceWindow::factory()->create(['code' => 'WINDOW_A', 'name' => 'Window A']);
        $this->windowB = ServiceWindow::factory()->create(['code' => 'WINDOW_B', 'name' => 'Window B']);
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

        $this->actingAs($client)->post('/admin/windows', [
            'name' => 'Sneaky', 'code' => 'SNEAKY', 'avg_service_minutes' => 10,
        ])->assertForbidden();

        $this->actingAs($client)->post('/admin/holidays', [
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

    private function slot(array $overrides = []): TimeSlot
    {
        return TimeSlot::create(array_merge([
            'service_id' => Service::factory()->create(['service_window_id' => $this->windowA->id])->id,
            'service_window_id' => $this->windowA->id,
            'slot_date' => today()->addDay()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => today()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => today()->addDay()->setTime(9, 30)->toDateTimeString(),
            'capacity' => 4,
            'booked_count' => 0,
            'is_open' => true,
        ], $overrides));
    }

    public function test_capacity_can_be_raised(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['capacity' => 9, 'is_open' => '1'])
            ->assertSessionHas('success');

        $this->assertSame(9, $slot->fresh()->capacity);
    }

    public function test_capacity_cannot_drop_below_the_clients_already_booked(): void
    {
        // Below booked_count the slot is permanently over-subscribed: isBookable()
        // goes false for everyone and remainingCapacity() goes negative, so the
        // clients already booked would vanish from the slot they hold.
        $slot = $this->slot(['capacity' => 4, 'booked_count' => 3]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['capacity' => 2, 'is_open' => '1'])
            ->assertSessionHas('error');

        $this->assertSame(4, $slot->fresh()->capacity);
    }

    public function test_capacity_may_equal_the_number_booked(): void
    {
        $slot = $this->slot(['capacity' => 4, 'booked_count' => 3]);

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['capacity' => 3, 'is_open' => '1'])
            ->assertSessionHas('success');

        $this->assertSame(3, $slot->fresh()->capacity);
    }

    public function test_a_slot_cannot_be_left_open_with_zero_capacity(): void
    {
        $slot = $this->slot();

        $this->actingAs($this->admin)
            ->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['capacity' => 0, 'is_open' => '1'])
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
        $holiday = Holiday::create([
            'date' => '2026-12-25', 'name' => 'Christmas Day',
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

    public function test_a_refused_capacity_change_is_audited(): void
    {
        $slot = $this->slot(['capacity' => 4, 'booked_count' => 3]);

        $this->actingAs($this->admin)->from(route('admin.slots.index'))
            ->put("/admin/slots/{$slot->id}", ['capacity' => 1, 'is_open' => '1']);

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
