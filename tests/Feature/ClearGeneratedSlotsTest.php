<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Clear generated slots" danger-zone action on the slot templates page.
 *
 * Recovery path for an administrator who saved a wrong template, ran Rebuild,
 * and needs to remove the generated rows without clicking through every day
 * in the Daily Slots view.
 */
class ClearGeneratedSlotsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $window = ServiceWindow::factory()->create(['is_active' => true]);

        $this->service = Service::factory()->create([
            'service_window_id' => $window->id,
            'is_active'         => true,
        ]);

        $this->admin = User::factory()->admin()->create([
            'service_window_id' => $window->id,
        ]);
    }

    // -----------------------------------------------------------------------

    public function test_future_unbooked_slots_are_deleted(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'slot_date'    => today()->addDay()->toDateString(),
            'booked_count' => 0,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.templates.clearSlots', $this->service))
            ->assertRedirect(route('admin.templates.index', $this->service))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('time_slots', ['id' => $slot->id]);
    }

    public function test_slots_with_bookings_are_preserved(): void
    {
        $bookedSlot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'slot_date'    => today()->addDay()->toDateString(),
            'booked_count' => 1,
        ]);

        $unbookedSlot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'slot_date'    => today()->addDays(2)->toDateString(),
            'booked_count' => 0,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.templates.clearSlots', $this->service));

        $this->assertDatabaseHas('time_slots', ['id' => $bookedSlot->id]);
        $this->assertDatabaseMissing('time_slots', ['id' => $unbookedSlot->id]);
    }

    public function test_past_slots_are_never_touched(): void
    {
        $pastSlot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'slot_date'    => today()->subDay()->toDateString(),
            'booked_count' => 0,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.templates.clearSlots', $this->service));

        $this->assertDatabaseHas('time_slots', ['id' => $pastSlot->id]);
    }

    public function test_slots_for_other_services_are_not_affected(): void
    {
        $other = Service::factory()->create([
            'service_window_id' => $this->service->service_window_id,
        ]);

        $otherSlot = TimeSlot::factory()->create([
            'service_id'   => $other->id,
            'slot_date'    => today()->addDay()->toDateString(),
            'booked_count' => 0,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.templates.clearSlots', $this->service));

        $this->assertDatabaseHas('time_slots', ['id' => $otherSlot->id]);
    }

    public function test_success_message_reports_the_count(): void
    {
        TimeSlot::factory()->count(3)->create([
            'service_id'   => $this->service->id,
            'slot_date'    => today()->addDay()->toDateString(),
            'booked_count' => 0,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.templates.clearSlots', $this->service))
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, '3'));
    }

    public function test_when_nothing_to_clear_says_so(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.templates.clearSlots', $this->service))
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'No unbooked'));
    }

    public function test_a_guest_cannot_clear_slots(): void
    {
        $this->delete(route('admin.templates.clearSlots', $this->service))
            ->assertRedirect(route('login'));
    }

    public function test_a_staff_member_cannot_clear_slots(): void
    {
        $staff = User::factory()->create([
            'role'              => 'staff',
            'service_window_id' => $this->service->service_window_id,
        ]);

        $this->actingAs($staff)
            ->delete(route('admin.templates.clearSlots', $this->service))
            ->assertForbidden();
    }

    public function test_the_templates_page_shows_the_slot_count(): void
    {
        TimeSlot::factory()->count(2)->create([
            'service_id'   => $this->service->id,
            'slot_date'    => today()->addDay()->toDateString(),
            'booked_count' => 0,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.templates.index', $this->service))
            ->assertOk()
            ->assertSee('2')
            ->assertSee('Clear slots');
    }
}
