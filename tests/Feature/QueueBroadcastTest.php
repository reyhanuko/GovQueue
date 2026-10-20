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
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Broadcast coverage.
 *
 * Events are asserted through Event::fake() so the tests check what is emitted
 * without needing a running Reverb server.
 */
class QueueBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private User $staff;

    private int $slotCounter = 0;

    /** Tickets live on tomorrow's queue so the suite is time-independent. */
    private string $queueDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create([
            'code' => 'WINDOW_1',
            'name' => 'Window 1 - Certificates',
        ]);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
        $this->staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);
        $this->queueDate = today()->addDay()->toDateString();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function slot(): TimeSlot
    {
        $this->slotCounter++;
        // Tomorrow, so booking assertions cannot break depending on what time
        // of day the suite happens to run.
        $start = today()->addDay()->setTime(9, 0)->addMinutes(($this->slotCounter - 1) * 30);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    private function waiting(array $overrides = []): Appointment
    {
        $slot = $this->slot();

        $appointment = Appointment::create(array_merge([
            'reference_number' => 'GQ-B-'.uniqid(),
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'status' => 'checked_in',
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_number' => 'A-'.str_pad((string) (Appointment::count() + 1), 3, '0', STR_PAD_LEFT),
            'queue_date' => $slot->slot_date->toDateString(),
            'confirmed_at' => now(),
            'checked_in_at' => now(),
        ], $overrides));

        $slot->increment('booked_count');

        return $appointment;
    }

    /* ------------------------------------------------------------------ */

    public function test_checking_in_broadcasts_a_status_change(): void
    {
        Event::fake([AppointmentStatusChanged::class, QueueUpdated::class]);

        $appointment = $this->waiting(['status' => 'confirmed', 'checked_in_at' => null]);

        // Inside the check-in window (decision D7), so the test is about the
        // broadcast and not about the hour of the day it runs in.
        Carbon::setTestNow($appointment->timeSlot->start_at);

        app(QueueService::class)->checkIn($appointment, $this->staff);

        Event::assertDispatched(AppointmentStatusChanged::class);
        Event::assertDispatched(QueueUpdated::class);
    }

    public function test_calling_next_broadcasts_a_status_change(): void
    {
        Event::fake([AppointmentStatusChanged::class, QueueUpdated::class]);

        $this->waiting();
        app(QueueService::class)->callNext($this->window, $this->staff, $this->queueDate);

        Event::assertDispatched(AppointmentStatusChanged::class);
    }

    public function test_completing_broadcasts_a_status_change(): void
    {
        Event::fake([AppointmentStatusChanged::class, QueueUpdated::class]);

        $this->waiting();
        $called = app(QueueService::class)->callNext($this->window, $this->staff, $this->queueDate);
        app(QueueService::class)->complete($called, $this->staff);

        Event::assertDispatched(AppointmentStatusChanged::class);
    }

    public function test_verifying_priority_broadcasts_because_the_queue_reorders(): void
    {
        Event::fake([AppointmentStatusChanged::class, QueueUpdated::class]);

        $appointment = $this->waiting([
            'claims_senior_citizen' => true,
            'priority_reference' => 'SC-1',
        ]);

        app(QueueService::class)->verifyPriority($appointment, $this->staff);

        Event::assertDispatched(AppointmentStatusChanged::class);
    }

    public function test_booking_broadcasts_a_status_change(): void
    {
        Event::fake([AppointmentStatusChanged::class, QueueUpdated::class]);

        $client = User::factory()->client()->create();
        $this->slot();

        app(BookingService::class)->book($client, $this->service, $this->slot()->id);

        Event::assertDispatched(AppointmentStatusChanged::class);
    }

    public function test_cancelling_broadcasts_a_status_change(): void
    {
        Event::fake([AppointmentStatusChanged::class, QueueUpdated::class]);

        $appointment = $this->waiting(['status' => 'confirmed']);

        app(BookingService::class)->cancel($appointment, 'test');

        Event::assertDispatched(AppointmentStatusChanged::class);
    }

    public function test_the_window_event_broadcasts_on_a_public_channel(): void
    {
        $event = new QueueUpdated($this->window, today()->toDateString());

        $this->assertSame('queue.WINDOW_1', $event->broadcastOn()->name);
        $this->assertStringNotContainsString('private-', $event->broadcastOn()->name,
            'The lobby display is not signed in, so its channel must be public.');
    }

    public function test_the_window_event_carries_no_client_data(): void
    {
        // Privacy: the lobby display is unauthenticated, so the payload must not
        // leak names, services, or priority claims.
        $payload = (new QueueUpdated($this->window, today()->toDateString()))->broadcastWith();

        $this->assertSame(['window', 'window_name', 'queue_date', 'at'], array_keys($payload));
    }

    public function test_the_client_event_uses_private_channels(): void
    {
        $appointment = $this->waiting();

        $channels = (new AppointmentStatusChanged($appointment))->broadcastOn();

        $this->assertCount(2, $channels);
        foreach ($channels as $channel) {
            $this->assertStringStartsWith('private-', $channel->name);
        }
    }

    public function test_the_client_event_carries_position_and_wait(): void
    {
        $first = $this->waiting();
        $second = $this->waiting();

        $this->assertNotNull($first);

        $event = new AppointmentStatusChanged($first, position: 1, estimatedWaitMinutes: 0);
        $payload = $event->broadcastWith();

        $this->assertSame(1, $payload['position']);
        $this->assertSame(0, $payload['estimated_wait_minutes']);
        $this->assertSame($first->queue_number, $payload['queue_number']);
        $this->assertArrayNotHasKey('client_name', $payload);
    }

    public function test_a_broken_broadcaster_never_breaks_the_request(): void
    {
        // A lobby display failing to update must not stop a client being served.
        $queue = app(QueueService::class);
        $this->waiting();

        Event::listen(QueueUpdated::class, function () {
            throw new \RuntimeException('Reverb is down');
        });

        $called = $queue->callNext($this->window, $this->staff, $this->queueDate);

        $this->assertNotNull($called);
        $this->assertSame('serving', $called->status->value);
    }
}
