<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in to GovQueue');
    }

    public function test_a_client_can_sign_in_and_lands_on_their_appointments(): void
    {
        $user = User::factory()->client()->create(['password' => 'password123']);

        // /dashboard is the single post-login router; it decides the destination by role.
        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->get('/dashboard')->assertRedirect(route('client.appointments.index'));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_staff_land_on_the_staff_console(): void
    {
        $user = User::factory()->staff()->create(['password' => 'password123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));

        $this->get('/dashboard')->assertRedirect(route('staff.dashboard'));
    }

    public function test_admin_lands_on_the_admin_dashboard(): void
    {
        $user = User::factory()->admin()->create(['password' => 'password123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));

        $this->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $user = User::factory()->client()->create(['password' => 'password123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_accounts_cannot_sign_in(): void
    {
        // The project deactivates rather than deletes accounts, so sign-in has
        // to actively refuse them.
        $user = User::factory()->client()->inactive()->create(['password' => 'password123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_user_with_an_existing_session_is_logged_out(): void
    {
        $user = User::factory()->client()->create(['password' => 'password123']);

        $this->actingAs($user)->get('/my-appointments')->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user)->get('/my-appointments')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    public function test_logging_out_ends_the_session(): void
    {
        $user = User::factory()->client()->create(['password' => 'password123']);

        $this->actingAs($user)->post('/logout')->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout']);
    }
}
