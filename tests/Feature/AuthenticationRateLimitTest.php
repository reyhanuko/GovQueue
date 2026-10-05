<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Abuse limits on the two public entry points.
 *
 * Both were unthrottled. On a form that anyone on the internet can reach, that is
 * password guessing against every client account in the municipality, and one
 * person scripting hundreds of fake residents into the list staff work from.
 *
 * The limiter is keyed per account *and* per account+IP: the per-account limit alone
 * still allows a spray across many addresses from a single connection, which is
 * what credential stuffing looks like.
 */
class AuthenticationRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login|resident@govqueue.test');
        RateLimiter::clear('login-ip|127.0.0.1|resident@govqueue.test');
        RateLimiter::clear('register|127.0.0.1');
    }

    private function client(): User
    {
        return User::factory()->client()->create([
            'email' => 'resident@govqueue.test',
            'password' => 'the-real-password',
        ]);
    }

    private function attempt(string $email = 'resident@govqueue.test', string $password = 'wrong-password')
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    /* ------------------------------------------------------------------ */
    /* Sign in */
    /* ------------------------------------------------------------------ */

    public function test_wrong_passwords_are_rejected_before_the_limit(): void
    {
        $this->client();

        $this->attempt()->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_attempt_is_counted_against_the_address(): void
    {
        $this->client();

        $this->attempt();

        $this->assertSame(
            1,
            RateLimiter::attempts('login|resident@govqueue.test'),
            'A failed attempt must be counted, or the limit never triggers.'
        );
    }

    public function test_repeated_failures_are_locked_out(): void
    {
        $this->client();

        // Five failures are allowed; the sixth should be refused outright.
        foreach (range(1, 5) as $ignored) {
            $this->attempt()->assertSessionHasErrors('email');
        }

        $response = $this->attempt();

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many sign-in attempts',
            session('errors')->first('email'),
            'A locked-out client is told so on the form, not shown a bare 429 page.'
        );

        $this->assertGuest();
    }

    public function test_a_locked_out_client_cannot_get_in_even_with_the_right_password(): void
    {
        $this->client();

        foreach (range(1, 6) as $ignored) {
            $this->attempt();
        }

        $this->attempt('resident@govqueue.test', 'the-real-password')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_correct_sign_in_clears_the_counter(): void
    {
        $this->client();

        $this->attempt();
        $this->attempt();

        $this->assertGreaterThan(0, RateLimiter::attempts('login|resident@govqueue.test'));

        $this->attempt('resident@govqueue.test', 'the-real-password')
            ->assertRedirect();

        $this->assertAuthenticated();

        $this->assertSame(
            0,
            RateLimiter::attempts('login|resident@govqueue.test'),
            'A client who signs in successfully must start from a clean slate.'
        );
    }

    public function test_a_successful_sign_in_does_not_count_towards_the_limit(): void
    {
        // Someone who signs in and out repeatedly must never lock themselves out.
        $this->client();

        foreach (range(1, 8) as $ignored) {
            $this->post('/login', [
                'email' => 'resident@govqueue.test',
                'password' => 'the-real-password',
            ]);

            $this->post('/logout');
        }

        $this->assertGuest();

        $this->attempt('resident@govqueue.test', 'the-real-password')->assertRedirect();
    }

    public function test_one_address_being_locked_out_does_not_lock_out_another(): void
    {
        $this->client();
        User::factory()->client()->create([
            'email' => 'other@govqueue.test',
            'password' => 'another-password',
        ]);

        foreach (range(1, 6) as $ignored) {
            $this->attempt();
        }

        $this->attempt('resident@govqueue.test', 'the-real-password')->assertSessionHasErrors('email');

        $this->attempt('other@govqueue.test', 'another-password')->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_the_lockout_message_does_not_reveal_whether_the_account_exists(): void
    {
        User::factory()->client()->create([
            'email' => 'known@govqueue.test',
            'password' => 'the-real-password',
        ]);

        $this->attempt('known@govqueue.test', 'wrong');
        $known = session('errors')->first('email');

        $this->post('/logout');
        $this->attempt('never-registered@govqueue.test', 'wrong');
        $unknown = session('errors')->first('email');

        $this->assertSame($known, $unknown);
    }

    public function test_the_email_match_is_case_insensitive(): void
    {
        $this->client();

        $this->attempt('RESIDENT@GOVQUEUE.TEST', 'the-real-password')->assertRedirect();

        $this->assertAuthenticated();
    }

    /* ------------------------------------------------------------------ */
    /* Registration */
    /* ------------------------------------------------------------------ */

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Resident',
            'email' => 'new-resident@govqueue.test',
            'phone' => '09171234567',
            'barangay' => 'Poblacion',
            'birthdate' => '1995-01-01',
            'password' => 'a-good-password',
            'password_confirmation' => 'a-good-password',
            'privacy_consent' => '1',
        ], $overrides);
    }

    public function test_registration_works_normally(): void
    {
        $this->post('/register', $this->registrationPayload())
            ->assertRedirect(route('client.appointments.index'));

        $this->assertDatabaseHas('users', ['email' => 'new-resident@govqueue.test']);
    }

    public function test_repeated_registrations_from_one_connection_are_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/register', $this->registrationPayload([
                'email' => 'resident-'.$i.'@govqueue.test',
            ]))->assertSessionHasNoErrors();

            // A fresh session each time, which is what the attacker would use.
            // Without this the guest middleware would simply redirect the second
            // request because registering signed the first one in, and the test
            // would pass for the wrong reason.
            $this->app['auth']->forgetGuards();
            $this->flushSession();
        }

        $created = User::pluck('email')
            ->filter(fn ($e) => str_starts_with($e, 'resident-'))
            ->values()
            ->all();

        $this->assertCount(5, $created, 'Created: '.implode(', ', $created));

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $response = $this->post('/register', $this->registrationPayload([
            'email' => 'resident-6@govqueue.test',
        ]));

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many accounts',
            session('errors')->first('email')
        );

        $this->assertDatabaseMissing('users', ['email' => 'resident-6@govqueue.test']);
    }

    public function test_a_rejected_registration_does_not_consume_the_allowance(): void
    {
        // Only successful registrations count, or a resident who mistypes their
        // phone number five times could never register at all.
        foreach (range(1, 5) as $ignored) {
            $this->post('/register', $this->registrationPayload(['phone' => '123']))
                ->assertSessionHasErrors('phone');

            $this->app['auth']->forgetGuards();
            $this->flushSession();
        }

        $this->post('/register', $this->registrationPayload(['email' => 'finally@govqueue.test']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'finally@govqueue.test']);
    }

    /**
     * The counter has to survive between requests, not reset on each page load.
     *
     * Covered behaviourally above - the lockout tests issue five separate HTTP
     * requests and only lock out on the sixth, which is the property that matters.
     */
    public function test_the_counter_survives_between_requests(): void
    {
        $this->client();

        $this->attempt();
        $first = RateLimiter::attempts('login|resident@govqueue.test');

        $this->attempt();
        $second = RateLimiter::attempts('login|resident@govqueue.test');

        $this->assertSame(1, $first);
        $this->assertSame(2, $second, 'Each separate request must add to the same counter.');
    }
}
