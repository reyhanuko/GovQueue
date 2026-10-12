<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The session cookie's SameSite attribute.
 *
 * This one config value decides whether the session rides along on a cross-site
 * request, so it is asserted rather than left to a default that changed between
 * framework versions. The requirement itself comes from GovQueue's own routes:
 * every state-changing action is a POST/PUT/DELETE carrying a CSRF token, and
 * nothing in the application reads state from a GET. There is therefore no
 * legitimate cross-site navigation that needs the cookie, which is what makes
 * strict affordable here.
 */
class SessionCookieHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_session_cookie_is_strict(): void
    {
        $this->assertSame(
            'strict',
            config('session.same_site'),
            'The session cookie SameSite attribute drifted from strict. Every mutating '
            .'route is a token-carrying POST/PUT/DELETE, so nothing legitimate needs '
            .'the cookie on a cross-site request.'
        );
    }

    public function test_a_signed_in_clients_cookie_is_marked_strict(): void
    {
        // Asserted through a real response: config could say one thing and the
        // emitted Set-Cookie header another, and only the header reaches a browser.
        $response = $this->actingAs(User::factory()->client()->create())
            ->get('/my-appointments')
            ->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie, 'No session cookie was issued, so SameSite was never asserted.');
        $this->assertSame(
            'strict',
            $cookie->getSameSite(),
            'The emitted session cookie is not SameSite=Strict.'
        );
    }

    public function test_the_cookie_is_httponly(): void
    {
        // A script able to read the session id could forge an authenticated
        // request regardless of SameSite.
        $response = $this->actingAs(User::factory()->client()->create())
            ->get('/my-appointments')
            ->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly(), 'The session cookie is readable by JavaScript.');
    }

    public function test_the_mutating_routes_still_carry_csrf_protection(): void
    {
        // SameSite is defence in depth, not a replacement. If these routes ever
        // lose their token check, the cookie attribute alone would be the only
        // thing standing between a cross-site POST and a changed booking.
        //
        // Asserted from the route's middleware stack rather than by posting
        // without a token: Laravel's CSRF middleware skips itself under
        // `runningUnitTests()`, so a live request would sail through and this test
        // would pass while proving nothing.
        $names = ['client.booking.store', 'client.appointments.reschedule.store'];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] is missing.");

            $this->assertContains(
                'web',
                $route->gatherMiddleware(),
                "Route [{$name}] left the web group, which is what carries CSRF protection."
            );
        }

        // And the group itself must still hold the middleware, since being in the
        // group is only meaningful while the group includes it.
        $webGroup = app(Kernel::class)->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(
            ValidateCsrfToken::class,
            $webGroup,
            'CSRF validation has been removed from the web middleware group.'
        );
    }

    public function test_strict_does_not_break_ordinary_navigation(): void
    {
        // The point of the strict setting failing would be a broken application.
        // A client walking their own normal path must not hit anything unusual.
        $user = User::factory()->client()->create();

        // /dashboard redirects by design - it exists to route each role to its own
        // console - so the destination is what matters here.
        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('client.appointments.index'));

        $this->actingAs($user)->get('/my-appointments')->assertOk();
        $this->actingAs($user)->get('/profile')->assertOk();
        $this->actingAs($user)->get('/services')->assertOk();
    }

    public function test_the_public_display_needs_no_session(): void
    {
        // The lobby board runs on a screen nobody is signed in on. If the session
        // handling were broken it would show an error page rather than a queue.
        $this->assertGuest();

        $this->get('/queue-display')->assertOk();
    }
}
