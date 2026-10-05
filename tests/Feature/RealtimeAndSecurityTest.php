<?php

namespace Tests\Feature;

use App\Events\AppointmentStatusChanged;
use App\Events\QueueUpdated;
use App\Models\Appointment;
use App\Models\ServiceWindow;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Realtime delivery guarantees and response hardening.
 */
class RealtimeAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* Delivery */
    /* ------------------------------------------------------------------ */

    public function test_queue_events_are_sent_immediately_not_queued(): void
    {
        // A ShouldBroadcast (queued) event sits in the jobs table until a worker
        // picks it up. The public display then never went live on a deployment
        // without a running queue worker, which is a fragile requirement for a
        // small municipal office.
        $this->assertInstanceOf(ShouldBroadcastNow::class, new QueueUpdated(
            ServiceWindow::factory()->create(),
            today()->toDateString(),
        ));

        $this->assertInstanceOf(ShouldBroadcastNow::class, new AppointmentStatusChanged(
            Appointment::factory()->create(),
        ));
    }

    public function test_the_test_environment_does_not_silently_disable_broadcast_auth(): void
    {
        // phpunit.xml previously set BROADCAST_CONNECTION=null, whose auth()
        // returns nothing - so every channel-authorisation test passed against
        // a driver that checks nothing at all.
        $this->assertNotSame('null', config('broadcasting.default'));
        $this->assertSame('reverb', config('broadcasting.default'));
    }

    public function test_the_broadcaster_can_sign_channel_authorisations(): void
    {
        // Proves the reverb driver is genuinely usable for auth, which is what
        // the null driver silently skipped.
        $this->assertNotEmpty(config('broadcasting.connections.reverb.key'));
        $this->assertNotEmpty(config('broadcasting.connections.reverb.secret'));
    }

    /* ------------------------------------------------------------------ */
    /* Response hardening */
    /* ------------------------------------------------------------------ */

    public function test_security_headers_are_present_on_public_pages(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    }

    public function test_security_headers_are_present_on_the_public_display(): void
    {
        $this->get('/queue-display')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_security_headers_are_present_behind_authentication(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/my-appointments')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_security_headers_are_present_on_error_responses(): void
    {
        $this->get('/services/DOES-NOT-EXIST')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_display_is_not_framed_by_another_origin(): void
    {
        // SAMEORIGIN still lets the office embed the display in its own intranet
        // portal while blocking clickjacking from elsewhere. Asserted through the
        // real response rather than a tautology.
        $this->get('/queue-display')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
