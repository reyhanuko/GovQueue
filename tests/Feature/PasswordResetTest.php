<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * "I forgot my password".
 *
 * Before Sprint 9 there was no reset flow at all: a client who forgot their
 * password was locked out of booking, viewing and cancelling appointments with no
 * way back in, because no counter process could reset it for them.
 *
 * Most of these tests are about NOT telling an attacker things. A reset form that
 * says "no account uses that address" is a way to enumerate which residents hold
 * accounts with the municipality.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    /**
     * A real reset token.
     *
     * Password::reset() looks the token up in password_reset_tokens, so a made-up
     * string always fails. Every test that completes a reset uses this.
     */
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create([
            'email' => 'resident@govqueue.test',
            'password' => 'original-password',
        ]);

        $this->token = Password::broker()->createToken($this->client);
    }

    /* ------------------------------------------------------------------ */
    /* Requesting a link */
    /* ------------------------------------------------------------------ */

    public function test_the_request_form_is_reachable(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Reset your password');
    }

    public function test_the_sign_in_page_offers_a_way_back_in(): void
    {
        // The feature is worthless if nobody can find it.
        $this->get('/login')
            ->assertOk()
            ->assertSee('Forgot your password?')
            ->assertSee(route('password.request'), escape: false);
    }

    public function test_a_reset_email_is_sent(): void
    {
        Mail::fake();

        $this->post('/forgot-password', ['email' => 'resident@govqueue.test'])
            ->assertSessionHas('status');

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
            return $mail->hasTo('resident@govqueue.test');
        });
    }

    public function test_the_email_contains_a_working_signed_link(): void
    {
        $mailable = (new PasswordResetMail($this->client, 'a-token'))->resetUrl();

        // Signed: a tampered or hand-built URL must not open the form.
        $this->get($mailable)->assertOk()->assertSee('Choose a new password');

        // Drop the signature and it is rejected.
        $this->get('/reset-password/a-token?email=resident@govqueue.test')
            ->assertForbidden();
    }

    public function test_the_email_match_is_case_insensitive(): void
    {
        Mail::fake();

        $this->post('/forgot-password', ['email' => 'RESIDENT@GovQueue.Test'])
            ->assertSessionHas('status');

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo($this->client->email));
    }

    /* ------------------------------------------------------------------ */
    /* Not leaking who has an account */
    /* ------------------------------------------------------------------ */

    public function test_an_unknown_address_gets_the_same_answer_as_a_known_one(): void
    {
        Mail::fake();

        $this->post('/forgot-password', ['email' => 'resident@govqueue.test']);
        $known = session('status');

        $this->post('/forgot-password', ['email' => 'nobody@govqueue.test']);
        $unknown = session('status');

        $this->assertNotNull($known);
        $this->assertSame(
            $known,
            $unknown,
            'The response must be identical, or the form reveals which addresses are registered.'
        );

        // Exactly one mail, and it went to the address that exists.
        Mail::assertSent(PasswordResetMail::class, 1);
        Mail::assertSent(
            PasswordResetMail::class,
            fn (PasswordResetMail $mail) => $mail->hasTo('resident@govqueue.test')
        );
        Mail::assertNotSent(
            PasswordResetMail::class,
            fn (PasswordResetMail $mail) => $mail->hasTo('nobody@govqueue.test')
        );
    }

    public function test_a_deactivated_account_is_answered_identically_and_sent_nothing(): void
    {
        Mail::fake();

        User::factory()->create([
            'email' => 'gone@govqueue.test',
            'is_active' => false,
        ]);

        $this->post('/forgot-password', ['email' => 'gone@govqueue.test']);
        $deactivated = session('status');

        $this->post('/forgot-password', ['email' => 'nobody@govqueue.test']);
        $unknown = session('status');

        $this->assertSame(
            $deactivated,
            $unknown,
            'Telling a deactivated account apart would confirm the address exists.'
        );

        Mail::assertNothingSent();
    }

    public function test_an_invalid_email_is_still_a_validation_error(): void
    {
        // Malformed input is not an enumeration attempt, so it can be precise.
        $this->post('/forgot-password', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
    }

    public function test_a_missing_email_is_a_validation_error(): void
    {
        $this->post('/forgot-password', [])
            ->assertSessionHasErrors('email');
    }

    /* ------------------------------------------------------------------ */
    /* Choosing the new password */
    /* ------------------------------------------------------------------ */

    private function signedResetUrl(?string $token = null): string
    {
        return URL::temporarySignedRoute('password.reset', now()->addMinutes(60), [
            'token' => $token ?? $this->token,
            'email' => $this->client->email,
        ]);
    }

    public function test_a_client_can_choose_a_new_password(): void
    {
        $this->post('/reset-password', [
            'token' => $this->token,
            'email' => 'resident@govqueue.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('login'));

        $this->assertTrue(
            Hash::check('a-brand-new-password', $this->client->fresh()->password),
            'The new password must be usable.'
        );

        $this->assertFalse(
            Hash::check('original-password', $this->client->fresh()->password),
            'The old password must stop working.'
        );
    }

    public function test_the_new_password_is_hashed_not_stored_in_plain_text(): void
    {
        $this->post('/reset-password', [
            'token' => $this->token,
            'email' => 'resident@govqueue.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $stored = $this->client->fresh()->password;

        $this->assertNotSame('a-brand-new-password', $stored);
        $this->assertStringStartsWith('$2y$', $stored, 'The cast should have produced a bcrypt hash.');
    }

    public function test_mismatched_passwords_are_refused(): void
    {
        $this->post('/reset-password', [
            'token' => $this->token,
            'email' => 'resident@govqueue.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('original-password', $this->client->fresh()->password));
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->post('/reset-password', [
            'token' => $this->token,
            'email' => 'resident@govqueue.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    public function test_the_reset_link_can_only_be_used_once(): void
    {
        $this->post('/reset-password', [
            'token' => $this->token,
            'email' => 'resident@govqueue.test',
            'password' => 'first-new-password',
            'password_confirmation' => 'first-new-password',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('first-new-password', $this->client->fresh()->password));

        // Replaying the same link must not work: it is the token someone might
        // find in a forwarded email or a shared browser history.
        $this->from($this->signedResetUrl())
            ->post('/reset-password', [
                'token' => $this->token,
                'email' => 'resident@govqueue.test',
                'password' => 'second-new-password',
                'password_confirmation' => 'second-new-password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue(
            Hash::check('first-new-password', $this->client->fresh()->password),
            'A replayed link must not change the password again.'
        );
    }

    public function test_an_expired_link_is_refused(): void
    {
        $expired = URL::temporarySignedRoute('password.reset', now()->subMinute(), [
            'token' => $this->token,
            'email' => $this->client->email,
        ]);

        $this->get($expired)->assertForbidden();
    }

    public function test_an_invalid_token_is_refused_without_saying_which_part_failed(): void
    {
        $this->from($this->signedResetUrl())
            ->post('/reset-password', [
                'token' => 'the-wrong-token',
                'email' => 'resident@govqueue.test',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('original-password', $this->client->fresh()->password));
    }

    public function test_an_unsigned_reset_form_url_is_rejected(): void
    {
        // Without the signature middleware the form could be opened with any
        // guessed token, so this is a route-level guarantee.
        $this->get('/reset-password/guessed-token?email=resident@govqueue.test')
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Signing in afterwards */
    /* ------------------------------------------------------------------ */

    public function test_the_client_can_sign_in_with_the_new_password(): void
    {
        $this->post('/reset-password', [
            'token' => $this->token,
            'email' => 'resident@govqueue.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->post('/login', [
            'email' => 'resident@govqueue.test',
            'password' => 'a-brand-new-password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($this->client);
    }

    public function test_the_client_cannot_sign_in_with_the_old_password(): void
    {
        $this->post('/reset-password', [
            'token' => $this->token,
            'email' => 'resident@govqueue.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->post('/login', [
            'email' => 'resident@govqueue.test',
            'password' => 'original-password',
        ]);

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /* Ending other sessions */
    /* ------------------------------------------------------------------ */

    public function test_resetting_ends_the_account_other_sessions(): void
    {
        // The usual reason to reset is that someone else may have the password.
        // Leaving their session alive would defeat the point entirely.
        $this->post('/reset-password', [
            'token' => $this->token,
            'email' => 'resident@govqueue.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_the_remember_me_token_is_rotated(): void
    {
        $before = $this->client->remember_token;

        $this->post('/reset-password', [
            'token' => $this->token,
            'email' => 'resident@govqueue.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertNotSame($before, $this->client->fresh()->remember_token);
    }

    /* ------------------------------------------------------------------ */
    /* Audit and guest access */
    /* ------------------------------------------------------------------ */

    public function test_the_reset_is_audited(): void
    {
        $this->post('/reset-password', [
            'token' => $this->token,
            'email' => 'resident@govqueue.test',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.password_reset',
            'subject_type' => 'User',
            'subject_id' => $this->client->id,
        ]);
    }

    public function test_a_signed_in_client_is_sent_away_from_the_reset_forms(): void
    {
        // There is no reason to offer a signed-in client a password reset link,
        // and leaving it reachable invites self-targeted CSRF.
        $this->actingAs($this->client)->get('/forgot-password')->assertRedirect();
        $this->actingAs($this->client)->get($this->signedResetUrl())->assertRedirect();
    }

    public function test_staff_and_administrators_can_also_reset_their_own_password(): void
    {
        // The lockout is not a client-only problem: an administrator locked out of
        // the back office is worse.
        foreach ([
            User::factory()->staff()->create(),
            User::factory()->admin()->create(),
        ] as $account) {
            Mail::fake();

            $this->post('/forgot-password', ['email' => $account->email])
                ->assertSessionHas('status');

            Mail::assertSent(
                PasswordResetMail::class,
                fn (PasswordResetMail $mail) => $mail->hasTo($account->email)
            );
        }
    }
}
