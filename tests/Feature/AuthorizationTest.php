<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_cannot_reach_the_staff_console(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/staff')
            ->assertForbidden();
    }

    public function test_a_client_cannot_reach_the_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_staff_can_reach_the_staff_console_but_not_the_admin_area(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/staff')->assertOk();
        $this->actingAs($staff)->get('/admin')->assertForbidden();
    }

    public function test_admin_can_reach_both_consoles(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/staff')->assertOk();
    }

    public function test_staff_cannot_open_a_clients_appointment_list(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/my-appointments')
            ->assertForbidden();
    }

    public function test_a_client_only_booking_route_redirects_staff_instead_of_403(): void
    {
        // Booking sits behind role:client. A staff member following the link used
        // to land on a bare 403 with no way back, so the middleware sends them to
        // where they came from with an explanation instead.
        $service = Service::factory()->create();

        $response = $this->actingAs(User::factory()->staff()->create())
            ->from(route('services.index'))
            ->get(route('client.booking.create', $service));

        $response->assertRedirect(route('services.index'));
        $response->assertSessionHas('error');
    }

    public function test_a_client_only_booking_route_redirects_an_administrator_instead_of_403(): void
    {
        $service = Service::factory()->create();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->from(route('services.index'))
            ->get(route('client.booking.create', $service));

        $response->assertRedirect(route('services.index'));
        $response->assertSessionHas('error');
    }

    public function test_the_admin_area_still_returns_403_for_the_wrong_role(): void
    {
        // Only the booking flow gets the friendly redirect. Everywhere else a
        // permission problem still shows the 403 page, because there is no
        // honest "you meant another page" answer for a mistyped admin URL.
        $this->actingAs(User::factory()->staff()->create())
            ->get('/admin/notifications')
            ->assertForbidden();
    }

    public function test_role_helper_flags_match_the_stored_role(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $client = User::factory()->client()->create();

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->isBackOffice());
        $this->assertFalse($admin->isStaff());

        $this->assertTrue($staff->isStaff());
        $this->assertTrue($staff->isBackOffice());
        $this->assertFalse($staff->isAdmin());

        $this->assertTrue($client->isClient());
        $this->assertFalse($client->isBackOffice());
    }
}
