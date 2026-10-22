<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Resident',
            'email' => 'resident@example.test',
            'phone' => '09171234567',
            'barangay' => 'Poblacion',
            'address' => 'Poblacion',
            'birthdate' => '1990-05-15',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'privacy_consent' => '1',
        ], $overrides);
    }

    public function test_registration_screen_renders(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Create your GovQueue account')
            ->assertSee('RA 9994');
    }

    public function test_registration_screen_asks_for_no_priority_input(): void
    {
        // Priority is a per-transaction claim, made on the booking. The
        // account step asks for a date of birth and nothing else.
        $this->get('/register')
            ->assertOk()
            ->assertDontSee('name="claims_senior_citizen"', false)
            ->assertDontSee('name="claims_pwd"', false)
            ->assertDontSee('name="claims_pregnant"', false)
            ->assertDontSee('name="priority_reference_pwd"', false)
            ->assertDontSee('name="priority_reference_pregnant"', false);
    }

    public function test_a_client_can_register_and_is_signed_in(): void
    {
        $response = $this->post('/register', $this->payload());

        $response->assertRedirect(route('client.appointments.index'));

        $user = User::where('email', 'resident@example.test')->firstOrFail();

        $this->assertSame(UserRole::Client, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertAuthenticatedAs($user);
    }

    public function test_self_registration_cannot_create_a_staff_or_admin_account(): void
    {
        // Even if a client tampers with the request, the role is forced to client.
        $this->post('/register', $this->payload(['role' => 'admin']));

        $this->assertSame(UserRole::Client, User::where('email', 'resident@example.test')->value('role'));
    }

    public function test_registration_requires_consent_and_a_valid_phone(): void
    {
        $this->post('/register', $this->payload([
            'phone' => '12345',
            'privacy_consent' => null,
        ]))->assertSessionHasErrors(['phone', 'privacy_consent']);

        $this->assertGuest();
    }

    public function test_senior_citizen_is_auto_detected_from_birthdate(): void
    {
        $this->post('/register', $this->payload(['birthdate' => '1955-01-01']));

        $user = User::where('email', 'resident@example.test')->firstOrFail();

        $this->assertTrue($user->meetsSeniorCitizenAge());
        // Assert the threshold rather than a hard-coded age, so the test stays
        // correct as the calendar advances.
        $this->assertGreaterThanOrEqual(config('govqueue.senior_citizen_age'), $user->ageInYears());
        $this->assertTrue($user->claims_senior_citizen);
    }

    public function test_under_sixty_birthdate_does_not_trigger_senior_priority(): void
    {
        $this->post('/register', $this->payload(['birthdate' => '1990-05-15']));

        $user = User::where('email', 'resident@example.test')->firstOrFail();

        $this->assertFalse($user->meetsSeniorCitizenAge());
        $this->assertFalse($user->claims_senior_citizen);
    }

    public function test_registration_stores_no_account_level_priority_claim(): void
    {
        // A crafted post cannot put a PWD or pregnancy claim on the account.
        $this->post('/register', $this->payload([
            'claims_pwd' => '1',
            'claims_pregnant' => '1',
            'priority_reference_pwd' => 'PWD-1234567',
            'priority_reference_pregnant' => 'MC-2212',
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'resident@example.test')->firstOrFail();

        $this->assertFalse($user->claims_senior_citizen);
        $this->assertNull(session('priority_claims'));
    }

    public function test_registration_does_not_persist_a_submitted_senior_flag(): void
    {
        // The birthdate is the only evidence. A ticked box claiming senior
        // status on a birthdate that does not qualify changes nothing.
        $this->post('/register', $this->payload([
            'birthdate' => '1990-05-15',
            'claims_senior_citizen' => '1',
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'resident@example.test')->firstOrFail();

        $this->assertFalse($user->claims_senior_citizen);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->client()->create(['email' => 'resident@example.test']);

        $this->post('/register', $this->payload())->assertSessionHasErrors('email');
    }

    public function test_registration_is_audited(): void
    {
        $this->post('/register', $this->payload());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'client.registered',
            'user_id' => User::where('email', 'resident@example.test')->value('id'),
        ]);
    }

    public function test_guests_are_redirected_away_from_protected_pages(): void
    {
        $this->get('/my-appointments')->assertRedirect(route('login'));
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
