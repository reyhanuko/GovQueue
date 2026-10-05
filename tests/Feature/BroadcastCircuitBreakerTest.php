<?php

namespace Tests\Feature;

use App\Events\AppointmentStatusChanged;
use App\Events\QueueUpdated;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use App\Services\QueueBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The broadcast circuit breaker.
 *
 * GovQueue broadcasts queue events synchronously so the lobby display updates the
 * instant staff call a number. That makes the socket part of a client's request
 * latency, and with Reverb down a real booking measured at 2.7s: one connect
 * timeout per broadcast, two broadcasts per booking.
 *
 * The breaker trades a short blind window after a failure for that latency. These
 * tests pin the behaviour that makes the trade safe, including the awkward cases:
 * a cache outage, and recovery once the socket comes back.
 */
class BroadcastCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private User $staff;

    private Appointment $appointment;

    private string $queueDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queueDate = today()->addDay()->toDateString();

        $this->window = ServiceWindow::factory()->create([
            'code' => 'WINDOW_1',
            'name' => 'Window 1 - Certificates',
            'is_active' => true,
        ]);

        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
        ]);

        $this->staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        $this->appointment = Appointment::factory()->create([
            'service_id' => $this->service->id,
            'user_id' => User::factory()->create()->id,
            'queue_date' => $this->queueDate,
            'status' => 'confirmed',
        ]);

        Cache::forget('govqueue:broadcast-breaker:'.config('broadcasting.default'));
    }

    private function breakerKey(): string
    {
        return 'govqueue:broadcast-breaker:'.config('broadcasting.default');
    }

    public function test_a_healthy_broadcaster_attempts_to_broadcast(): void
    {
        Event::fake();

        $broadcaster = app(QueueBroadcaster::class);

        $this->assertTrue($broadcaster->isHealthy());

        $broadcaster->queueChanged($this->window, $this->queueDate);

        Event::assertDispatched(QueueUpdated::class);
    }

    public function test_a_failed_broadcast_trips_the_breaker_and_is_swallowed(): void
    {
        Log::spy();

        // Fail the trigger the way a dead Reverb does. Note: no Event::fake()
        // here - faking events intercepts dispatch before the broadcaster is
        // ever reached, so nothing could possibly fail.
        $this->breakTheSocket();

        $broadcaster = app(QueueBroadcaster::class);

        // Must not throw: a lobby display that cannot update must never stop a
        // client from being served.
        $broadcaster->queueChanged($this->window, $this->queueDate);

        $this->assertFalse($broadcaster->isHealthy());
        $this->assertNotNull(Cache::get($this->breakerKey()));

        Log::shouldHaveReceived('warning');
    }

    public function test_a_tripped_breaker_skips_further_attempts(): void
    {
        Event::fake();

        // A prior failure already tripped the breaker.
        Cache::put($this->breakerKey(), ['failed_at' => now()->toIso8601String()], 30);

        $broadcaster = app(QueueBroadcaster::class);

        $this->assertFalse($broadcaster->isHealthy());

        // Three separate calls, none of which should reach the transport. This is
        // the whole point: the cost of a dead socket is paid once.
        $broadcaster->queueChanged($this->window, $this->queueDate);
        $broadcaster->queueChanged($this->window, $this->queueDate);
        $broadcaster->appointmentChanged($this->appointment);

        // Asserted per-event rather than with assertNothingDispatched(), because
        // the cache and Eloquent fire events of their own during these calls.
        Event::assertNotDispatched(QueueUpdated::class);
        Event::assertNotDispatched(AppointmentStatusChanged::class);
    }

    public function test_a_successful_broadcast_clears_a_tripped_breaker(): void
    {
        Event::fake();

        Cache::put($this->breakerKey(), ['failed_at' => now()->toIso8601String()], 30);

        $broadcaster = app(QueueBroadcaster::class);
        $this->assertFalse($broadcaster->isHealthy());

        // The cooldown expires and Reverb is reachable again.
        Cache::forget($this->breakerKey());

        $broadcaster->queueChanged($this->window, $this->queueDate);

        Event::assertDispatched(QueueUpdated::class);
        $this->assertTrue($broadcaster->isHealthy());
    }

    public function test_a_booking_is_not_broken_by_a_dead_socket(): void
    {
        $this->breakTheSocket();

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => today()->addDay()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'start_at' => today()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end_at' => today()->addDay()->setTime(9, 30)->toDateTimeString(),
            'capacity' => 3,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        // The real end-to-end guarantee: the booking still commits even though
        // every broadcast along the way throws.
        $appointment = app(BookingService::class)
            ->book(User::factory()->create(), $this->service, $slot->id);

        $this->assertSame('confirmed', $appointment->status->value);
        $this->assertSame(1, $slot->fresh()->booked_count);
    }

    /**
     * A cache outage must not take broadcasting down with it.
     *
     * GovQueue's cache is the database, which is exactly the store most likely to
     * be the thing that is broken. If the breaker read threw, the request that
     * was merely trying to announce a queue change would fail instead.
     */
    public function test_a_cache_outage_degrades_to_always_attempting(): void
    {
        Event::fake();

        $broadcaster = app(QueueBroadcaster::class);

        // Simulate every cache read and write failing.
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('database is gone'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('database is gone'));
        Cache::shouldReceive('forget')->andThrow(new \RuntimeException('database is gone'));

        // No throw, and the broadcast still happens rather than being silently lost.
        $broadcaster->queueChanged($this->window, $this->queueDate);

        Event::assertDispatched(QueueUpdated::class);
    }

    /**
     * Make the underlying broadcast throw, the way a refused socket does.
     *
     * The real broadcaster is swapped out rather than the event fake, because a
     * faked event never reaches the transport at all.
     */
    private function breakTheSocket(): void
    {
        $this->app->bind(Factory::class, fn () => new class
        {
            public function connection($name = null)
            {
                return new class
                {
                    public function broadcast($event, $channels = [], $attributes = [])
                    {
                        throw new \RuntimeException('cURL error 7: Failed to connect to localhost port 8080');
                    }
                };
            }
        });
    }
}
