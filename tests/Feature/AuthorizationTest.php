<?php

namespace Tests\Feature;

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
