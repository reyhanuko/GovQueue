<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * Broadcast channel authorisation, exercised through the real
 * POST /broadcasting/auth endpoint that Laravel Echo calls.
 *
 * These cover a bug where the channel callbacks were written as if Laravel
 * substituted the {placeholder} values as named arguments. It passes them as
 * positional values derived from the channel name, so a nullable-typed
 * placeholder silently resolved to null and every private channel was denied -
 * meaning the client status board could never subscribe at all.
 */
class BroadcastChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $otherClient;

    private Appointment $appointment;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $service = Service::factory()->create(['service_window_id' => $this->window->id]);

        $start = today()->addDay()->setTime(9, 0);
        $slot = TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 5,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        $this->client = User::factory()->client()->create();
        $this->otherClient = User::factory()->client()->create();

        $this->appointment = Appointment::create([
            'reference_number' => 'GQ-CH-'.uniqid(),
            'user_id' => $this->client->id,
            'service_id' => $service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'status' => 'checked_in',
            'source' => 'online',
            'scheduled_at' => $start,
            'queue_number' => 'A-001',
            'queue_date' => $start->toDateString(),
            'checked_in_at' => now(),
        ]);
    }

    private function auth(User $user, string $channel, string $prefix = 'private')
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => $prefix.'-'.$channel,
            'socket_id' => '123.456',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Private channels */
    /* ------------------------------------------------------------------ */

    public function test_a_client_may_subscribe_to_their_own_appointment(): void
    {
        $this->auth($this->client, 'appointment.'.$this->appointment->id)
            ->assertOk();
    }

    public function test_a_client_may_not_subscribe_to_another_clients_appointment(): void
    {
        $this->auth($this->otherClient, 'appointment.'.$this->appointment->id)
            ->assertForbidden();
    }

    public function test_an_unknown_appointment_id_is_denied(): void
    {
        $this->auth($this->client, 'appointment.999999')->assertForbidden();
    }

    public function test_a_client_may_subscribe_to_their_own_client_channel(): void
    {
        $this->auth($this->client, 'client.'.$this->client->id)->assertOk();
    }

    public function test_a_client_may_not_subscribe_to_another_clients_channel(): void
    {
        $this->auth($this->client, 'client.'.$this->otherClient->id)->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Public channel */
    /* ------------------------------------------------------------------ */

    public function test_the_queue_channel_is_not_authorised_through_this_endpoint(): void
    {
        // Public channels never reach /broadcasting/auth: Echo connects with the
        // app key alone. Laravel would throw on a guest anyway, so the display
        // must not depend on this endpoint. Its privacy comes from the payload.
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'queue.'.$this->window->code,
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

    public function test_no_public_queue_channel_callback_is_registered(): void
    {
        // Regression guard: registering one looked harmless but turned any
        // authorisation attempt for it into a 500.
        $patterns = Broadcast::driver()->getChannels()->keys()->all();

        $this->assertNotContains('queue.{windowCode}', $patterns,
            'A public queue channel must not be registered for authorisation.');
        $this->assertContains('appointment.{appointmentId}', $patterns);
        $this->assertContains('client.{userId}', $patterns);
    }

    public function test_guests_cannot_subscribe_to_a_private_channel(): void
    {
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-appointment.'.$this->appointment->id,
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

    public function test_the_queue_channel_does_not_error_for_a_guest(): void
    {
        // Regression: an over-strict placeholder type hint raised a TypeError
        // here, which surfaced as a 500.
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-client.'.$this->client->id,
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Malformed requests */
    /* ------------------------------------------------------------------ */

    public function test_a_request_with_no_channel_is_rejected(): void
    {
        // Laravel's broadcaster throws AccessDeniedHttpException for an empty
        // channel_name rather than returning a validation error.
        $this->actingAs($this->client)
            ->postJson('/broadcasting/auth', ['socket_id' => '123.456'])
            ->assertForbidden();
    }

    public function test_a_channel_with_extra_segments_is_denied(): void
    {
        $this->actingAs($this->client)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-appointment.'.$this->appointment->id.'.extra',
                'socket_id' => '123.456',
            ])
            ->assertForbidden();
    }

    public function test_a_non_numeric_client_channel_id_is_denied_rather_than_crashing(): void
    {
        $this->actingAs($this->client)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-client.not-a-number',
                'socket_id' => '123.456',
            ])
            ->assertForbidden();
    }
}
