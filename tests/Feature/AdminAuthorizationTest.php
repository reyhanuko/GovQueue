<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A blanket check that every admin URL really is admin-only.
 *
 * Added after /admin/notifications was left inside the staff group by mistake,
 * which let any member of staff read a list of client email addresses and mobile
 * numbers. Asserting the middleware per route is what caught it; this test stops
 * the next one slipping through.
 */
class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every admin-prefixed route, paired with its HTTP verbs.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function adminRoutes(): array
    {
        return [
            'admin dashboard' => ['/admin', 'GET'],
            'notification log' => ['/admin/notifications', 'GET'],
        ];
    }

    /**
     * @dataProvider adminRoutes
     */
    public function test_guests_are_redirected(string $uri, string $method): void
    {
        $this->{$method}($uri)->assertRedirect(route('login'));
    }

    /**
     * @dataProvider adminRoutes
     */
    public function test_clients_are_forbidden(string $uri, string $method): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->{$method}($uri)
            ->assertForbidden();
    }

    /**
     * @dataProvider adminRoutes
     */
    public function test_staff_are_forbidden(string $uri, string $method): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->{$method}($uri)
            ->assertForbidden();
    }

    /**
     * @dataProvider adminRoutes
     */
    public function test_admins_are_allowed(string $uri, string $method): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->{$method}($uri)
            ->assertOk();
    }

    /**
     * Every /admin route must declare the admin role middleware.
     *
     * This is the structural guarantee behind the behavioural tests above: a
     * route added to the group later cannot accidentally lose its protection.
     */
    public function test_every_admin_route_requires_the_admin_role(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/'));

        $this->assertGreaterThan(0, $routes->count(), 'No admin routes were found at all.');

        $routes->each(function ($route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('role:admin', $middleware,
                'Route ['.$route->methods()[0].' '.$route->uri().'] is not protected by role:admin.');
        });
    }

    public function test_the_staff_console_is_not_admin_only(): void
    {
        // Staff legitimately need the queue console; the inverse must also hold.
        $this->actingAs(User::factory()->staff()->create())
            ->get('/staff')
            ->assertOk();
    }
}
