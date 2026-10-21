<?php

namespace Tests\Feature\Client;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): User
    {
        return User::factory()->client()->create(array_merge([
            'name' => 'Ana Dela Cruz',
            'email' => 'ana@example.test',
            'phone' => '09171234567',
            'barangay' => 'Poblacion',
            'address' => 'Poblacion',
            'birthdate' => '1990-05-15',
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana Dela Cruz',
            'email' => 'ana@example.test',
            'phone' => '09171234567',
            'barangay' => 'Poblacion',
            'address' => 'Poblacion',
            'birthdate' => '1990-05-15',
        ], $overrides);
    }

    public function test_a_client_can_view_their_profile(): void
    {
        $this->actingAs($this->client())
            ->get('/profile')
            ->assertOk()
            ->assertSee('My profile')
            ->assertSee('Ana Dela Cruz');
    }

    public function test_the_profile_explains_priority_eligibility(): void
    {
        $this->actingAs($this->client())
            ->get('/profile')
            ->assertOk()
            ->assertSee('Senior Citizen')
            ->assertSee('Person with Disability')
            ->assertSee('Pregnant Client')
            ->assertSee('RA 9994')
            ->assertSee('RA 10754');
    }

    public function test_a_client_can_update_their_details(): void
    {
        $user = $this->client();

        $this->actingAs($user)
            ->put('/profile', $this->payload(['phone' => '09181234567', 'barangay' => 'Tubod']))
            ->assertRedirect(route('client.profile.edit'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('09181234567', $user->phone);
        $this->assertSame('Tubod', $user->barangay);
    }

    public function test_updating_the_birthdate_grants_senior_priority(): void
    {
        $user = $this->client();

        $this->actingAs($user)
            ->put('/profile', $this->payload(['birthdate' => '1955-01-01']))
            ->assertSessionHas('success');

        $this->assertTrue($user->fresh()->claims_senior_citizen);
    }

    public function test_updating_the_birthdate_removes_stale_senior_priority(): void
    {
        // Holds the flag from a previously qualifying birthdate.
        $user = $this->client(['birthdate' => '1950-01-01', 'claims_senior_citizen' => true]);

        $this->actingAs($user)
            ->put('/profile', $this->payload(['birthdate' => '1990-01-01']))
            ->assertSessionHas('success');

        $this->assertFalse($user->fresh()->claims_senior_citizen);
    }

    /**
     * A corrected date of birth reaches the bookings the client still holds.
     *
     * A senior citizen claim is not an independent fact: it is the date of
     * birth on file, read when the booking was made. So a client who typed
     * their date of birth wrong kept a claim the system itself no longer
     * supports - and a client who had it wrong on file lost a priority they
     * were entitled to.
     */
    public function test_a_corrected_birthdate_reaches_the_appointments_the_client_still_holds(): void
    {
        $user = $this->client(['birthdate' => '1950-01-01', 'claims_senior_citizen' => true]);

        $appointment = Appointment::factory()->create([
            'user_id' => $user->id,
            'status' => 'confirmed',
            'claims_senior_citizen' => true,
            'priority_verified_at' => null,
        ]);

        // The birthdate on file turns out to be wrong: the client was born
        // long after 1950.
        $this->actingAs($user)
            ->put('/profile', $this->payload(['birthdate' => '1990-01-01']))
            ->assertSessionHas('success');

        $this->assertFalse(
            $appointment->fresh()->claims_senior_citizen,
            'The booking still claims senior priority that the date of birth no longer supports.',
        );
    }

    /**
     * A claim staff have already verified is their decision and is left
     * alone - the console shows the date of birth next to it, so a clerk can
     * see a date that does not add up and refuse it.
     */
    public function test_a_verified_claim_is_left_alone(): void
    {
        $user = $this->client(['birthdate' => '1950-01-01', 'claims_senior_citizen' => true]);

        $appointment = Appointment::factory()->create([
            'user_id' => $user->id,
            'status' => 'confirmed',
            'claims_senior_citizen' => true,
            'priority_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->put('/profile', $this->payload(['birthdate' => '1990-01-01']))
            ->assertSessionHas('success');

        $this->assertTrue(
            $appointment->fresh()->claims_senior_citizen,
            'A verified decision was rewritten by a profile edit.',
        );
    }

    public function test_a_client_cannot_submit_their_own_senior_flag(): void
    {
        $user = $this->client();

        $this->actingAs($user)
            ->put('/profile', $this->payload([
                'birthdate' => '1990-01-01',
                'claims_senior_citizen' => '1',
            ]))
            ->assertSessionHas('success');

        $this->assertFalse($user->fresh()->claims_senior_citizen,
            'Priority is derived from the birthdate, never from submitted input.');
    }

    public function test_the_email_must_stay_unique(): void
    {
        User::factory()->client()->create(['email' => 'taken@example.test']);

        $this->actingAs($this->client())
            ->put('/profile', $this->payload(['email' => 'taken@example.test']))
            ->assertSessionHasErrors('email');
    }

    public function test_a_client_can_keep_their_own_email(): void
    {
        $this->actingAs($this->client())
            ->put('/profile', $this->payload())
            ->assertSessionHasNoErrors();
    }

    public function test_the_phone_must_be_a_valid_mobile_number(): void
    {
        $this->actingAs($this->client())
            ->put('/profile', $this->payload(['phone' => '123']))
            ->assertSessionHasErrors('phone');
    }

    public function test_a_future_birthdate_is_rejected(): void
    {
        $this->actingAs($this->client())
            ->put('/profile', $this->payload(['birthdate' => now()->addYear()->toDateString()]))
            ->assertSessionHasErrors('birthdate');
    }

    public function test_a_client_can_change_their_password(): void
    {
        $user = User::factory()->client()->create(['password' => 'password']);

        $this->actingAs($user)
            ->put('/profile/password', [
                'current_password' => 'password',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    public function test_changing_a_password_requires_the_current_one(): void
    {
        $user = User::factory()->client()->create(['password' => 'password']);

        $this->actingAs($user)
            ->put('/profile/password', [
                'current_password' => 'wrong',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_guests_cannot_reach_the_profile(): void
    {
        $this->get('/profile')->assertRedirect(route('login'));
    }

    public function test_staff_cannot_reach_the_client_profile(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/profile')
            ->assertForbidden();
    }
}
