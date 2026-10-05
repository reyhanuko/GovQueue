<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The profile screens as a client actually reaches them.
 *
 * The existing ProfileTest called `->put('/profile')` directly, which proved the
 * controller worked and nothing about whether a person could use the form. A
 * browser <form> can only send GET or POST, so every form targeting a PUT route
 * needs a `@method('PUT')` spoof field.
 *
 * Both profile forms were missing it, so the entire screen returned 405
 * Method Not Allowed: no client could edit their name, phone, address or
 * birthdate, and none could change their password. Every profile test passed the
 * whole time, because none of them went near the form.
 *
 * These tests submit the way a browser does - a POST carrying whatever fields the
 * rendered page actually contains.
 */
class ClientProfileFormTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create([
            'email' => 'resident@govqueue.test',
            'password' => 'original-password',
            'name' => 'Original Name',
            'phone' => '09171234567',
            'barangay' => 'Poblacion',
            'address' => 'Old Address',
            'birthdate' => '1990-05-05',
        ]);
    }

    /**
     * The hidden fields of the one form that targets $action.
     *
     * Parsed per form rather than page-wide on purpose. The profile page carries
     * three forms sharing a CSRF token, and reading the page as a whole would hand
     * every form a _method spoof it may not declare - which is exactly the bug
     * this class exists to catch.
     *
     * @return array<string, string>
     */
    private function hiddenFieldsFor(string $action): array
    {
        $html = $this->actingAs($this->client)->get('/profile')->assertOk()->getContent();

        $chunks = preg_split('/<form/i', $html) ?: [];

        foreach ($chunks as $chunk) {
            if (! str_contains($chunk, 'action="'.$action.'"')) {
                continue;
            }

            $fields = [];

            preg_match_all('/<input[^>]*type="hidden"[^>]*>/i', $chunk, $inputs);

            foreach ($inputs[0] as $input) {
                preg_match('/name="([^"]+)"/', $input, $name);
                preg_match('/value="([^"]*)"/', $input, $value);

                if ($name) {
                    $fields[$name[1]] = $value[1] ?? '';
                }
            }

            return $fields;
        }

        $this->fail("No form on /profile targets {$action}.");
    }

    /**
     * Submit a form exactly as the browser would.
     *
     * Whatever hidden fields the rendered form declares are sent, and nothing is
     * added. If the form is missing its @method spoof this genuinely posts a bare
     * POST and fails with 405, which is the whole point.
     */
    private function submitLikeABrowser(string $action, array $fields)
    {
        return $this->actingAs($this->client)
            ->post($action, array_merge($this->hiddenFieldsFor($action), $fields));
    }

    /* ------------------------------------------------------------------ */
    /* Method spoofing is present */
    /* ------------------------------------------------------------------ */

    public function test_every_profile_form_declares_a_put_spoof(): void
    {
        $html = $this->actingAs($this->client)->get('/profile')->assertOk()->getContent();

        // Three PUT routes, so three spoofs.
        $this->assertSame(
            3,
            substr_count($html, 'name="_method" value="PUT"'),
            'Every form targeting a PUT route needs a @method spoof, or it returns 405.'
        );
    }

    public function test_each_form_points_at_its_own_route(): void
    {
        $html = $this->actingAs($this->client)->get('/profile')->assertOk()->getContent();

        foreach ([
            route('client.profile.update'),
            route('client.profile.notifications'),
            route('client.profile.password'),
        ] as $action) {
            $this->assertStringContainsString(
                'action="'.$action.'"',
                $html,
                "Expected a form posting to {$action}."
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* The forms actually work when submitted */
    /* ------------------------------------------------------------------ */

    public function test_the_profile_form_submits_and_saves(): void
    {
        $response = $this->submitLikeABrowser(route('client.profile.update'), [
            'name' => 'Corrected Name',
            'email' => 'resident@govqueue.test',
            'phone' => '09181234567',
            'barangay' => 'Tubod',
            'address' => 'New Address',
            'birthdate' => '1991-02-02',
        ]);

        $response->assertRedirect(route('client.profile.edit'));
        $response->assertSessionHasNoErrors();

        $fresh = $this->client->fresh();

        $this->assertSame('Corrected Name', $fresh->name);
        $this->assertSame('Tubod', $fresh->barangay);
        $this->assertSame('New Address', $fresh->address);
    }

    public function test_the_password_form_submits_and_saves(): void
    {
        $response = $this->submitLikeABrowser(route('client.profile.password'), [
            'current_password' => 'original-password',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $response->assertRedirect(route('client.profile.edit'));
        $response->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(
            'a-new-password',
            $this->client->fresh()->password
        ));
    }

    public function test_the_notifications_form_submits_and_saves(): void
    {
        $response = $this->submitLikeABrowser(route('client.profile.notifications'), [
            'notify_email' => '1',
        ]);

        $response->assertRedirect(route('client.profile.edit'));

        $this->assertTrue((bool) $this->client->fresh()->notify_email);
        // The box was absent, so it must read as off rather than keeping the old
        // value - an unchecked checkbox is simply not in the payload.
        $this->assertFalse((bool) $this->client->fresh()->notify_sms);
    }

    /* ------------------------------------------------------------------ */
    /* And the same forms still validate */
    /* ------------------------------------------------------------------ */

    public function test_the_profile_form_still_rejects_bad_input(): void
    {
        // Fixing the method must not have bypassed validation.
        $response = $this->submitLikeABrowser(route('client.profile.update'), [
            'name' => '',
            'email' => 'not-an-email',
            'phone' => '123',
            'barangay' => '',
            'birthdate' => 'not-a-date',
        ]);

        $response->assertSessionHasErrors(['name', 'email', 'phone', 'barangay', 'birthdate']);
    }

    public function test_the_password_form_still_requires_the_current_password(): void
    {
        $response = $this->submitLikeABrowser(route('client.profile.password'), [
            'current_password' => 'the-wrong-password',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check(
            'original-password',
            $this->client->fresh()->password
        ));
    }
}
