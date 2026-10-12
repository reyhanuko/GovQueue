<?php

namespace Tests\Feature;

use App\Http\Middleware\CorrelateRequests;
use App\Models\Appointment;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The metrics scrape endpoint and the request correlation id.
 *
 * Two operational concerns, both about being able to answer a question after the
 * fact: "how is the office coping right now" and "which log lines belong to this
 * one booking".
 */
class MetricsAndCorrelationTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /* Access control */
    /* ------------------------------------------------------------------ */

    public function test_metrics_are_refused_without_a_token(): void
    {
        $response = $this->get('/metrics');

        // 404 rather than 401 or 403 on purpose. A 403 confirms the endpoint
        // exists and is worth attacking; 404 says nothing about either.
        $response->assertNotFound();
    }

    public function test_metrics_are_refused_with_the_wrong_token(): void
    {
        config(['app.metrics_token' => 'the-real-token']);

        $this->withHeader('X-Metrics-Token', 'guessing')
            ->get('/metrics')
            ->assertNotFound();
    }

    public function test_metrics_are_served_with_the_right_token(): void
    {
        config(['app.metrics_token' => 'the-real-token']);

        $response = $this->withHeader('X-Metrics-Token', 'the-real-token')
            ->get('/metrics');

        $response->assertOk();
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('govqueue_', $response->getContent());
    }

    public function test_metrics_fail_closed_when_no_token_is_configured(): void
    {
        // The property that matters on a fresh deployment: forgetting to set the
        // environment variable must close the endpoint, not open it.
        config(['app.metrics_token' => '']);

        $this->withHeader('X-Metrics-Token', '')
            ->get('/metrics')
            ->assertNotFound();

        // And an empty supplied token is never accepted, even with one configured.
        config(['app.metrics_token' => 'the-real-token']);

        $this->withHeader('X-Metrics-Token', '')
            ->get('/metrics')
            ->assertNotFound();
    }

    public function test_an_unconfigured_endpoint_is_indistinguishable_from_a_wrong_token(): void
    {
        // Otherwise "the endpoint is not configured" is a distinguishable state,
        // and a scanner can tell which deployments have metrics at all.
        $notConfigured = $this->get('/metrics');

        config(['app.metrics_token' => 'the-real-token']);
        $wrongToken = $this->withHeader('X-Metrics-Token', 'nope')->get('/metrics');

        $this->assertSame($notConfigured->getStatusCode(), $wrongToken->getStatusCode());
        $this->assertSame($notConfigured->getContent(), $wrongToken->getContent());
    }

    /* ------------------------------------------------------------------ */
    /* What the metrics do and do not contain */
    /* ------------------------------------------------------------------ */

    public function test_the_metrics_report_queue_state(): void
    {
        config(['app.metrics_token' => 't']);

        $window = ServiceWindow::factory()->create(['is_active' => true]);
        $service = Service::factory()->create(['service_window_id' => $window->id]);

        $this->ticket($service, $window, 'checked_in');
        $this->ticket($service, $window, 'completed');
        $this->ticket($service, $window, 'cancelled');

        $body = $this->withHeader('X-Metrics-Token', 't')->get('/metrics')->getContent();

        $this->assertStringContainsString('govqueue_appointments_today{status="checked_in"} 1', $body);
        $this->assertStringContainsString('govqueue_appointments_today{status="completed"} 1', $body);
        $this->assertStringContainsString('govqueue_appointments_today{status="cancelled"} 1', $body);
        $this->assertStringContainsString('govqueue_clients_waiting 1', $body);
    }

    public function test_the_metrics_never_expose_a_clients_details(): void
    {
        config(['app.metrics_token' => 't']);

        $window = ServiceWindow::factory()->create(['is_active' => true]);
        $service = Service::factory()->create([
            'service_window_id' => $window->id,
            'name' => 'Barangay Clearance',
        ]);

        $client = User::factory()->client()->create([
            'name' => 'Juan Dela Cruz',
            'phone' => '09171234567',
        ]);

        $this->ticket($service, $window, 'checked_in', ['user_id' => $client->id]);

        $body = $this->withHeader('X-Metrics-Token', 't')->get('/metrics')->getContent();

        // A scrape target holds these indefinitely and is read by anyone with
        // access to the monitoring stack, so the line is drawn well before
        // personal data: no names, no numbers, not even a service name that could
        // be traced to the one client who booked it.
        foreach (['Juan Dela Cruz', '09171234567', 'Barangay Clearance'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "Metrics exposed \"{$leak}\".");
        }
    }

    public function test_the_metrics_report_notification_failures(): void
    {
        // Where a silent failure would show up: notifications switched on with no
        // gateway configured, and clients quietly receiving nothing.
        config(['app.metrics_token' => 't']);

        NotificationLog::create([
            'appointment_id' => null,
            'channel' => 'sms',
            'type' => 'reminder',
            'status' => 'failed',
            'error_message' => 'gateway unreachable',
        ]);

        $body = $this->withHeader('X-Metrics-Token', 't')->get('/metrics')->getContent();

        $this->assertStringContainsString('govqueue_notifications{channel="sms",status="failed"} 1', $body);

        // The failure message can carry a gateway response, which is not something
        // to republish on an endpoint read by monitoring.
        $this->assertStringNotContainsString('gateway unreachable', $body);
    }

    public function test_the_metrics_are_well_formed_on_an_empty_database(): void
    {
        config(['app.metrics_token' => 't']);

        $body = $this->withHeader('X-Metrics-Token', 't')->get('/metrics')->getContent();

        // Every metric must carry a HELP and TYPE line, or a scrape target rejects
        // the whole payload rather than the one bad series.
        foreach (['govqueue_appointments_today', 'govqueue_active_windows',
            'govqueue_clients_waiting', 'govqueue_clients_being_served',
            'govqueue_open_slot_capacity'] as $metric) {
            $this->assertStringContainsString("# HELP {$metric} ", $body, "{$metric} has no HELP line.");
            $this->assertStringContainsString("# TYPE {$metric} gauge", $body, "{$metric} has no TYPE line.");
        }

        $this->assertStringContainsString('govqueue_appointments_today{status="none"} 0', $body);
        $this->assertStringEndsWith("\n", $body);
    }

    /* ------------------------------------------------------------------ */
    /* Correlation id */
    /* ------------------------------------------------------------------ */

    public function test_every_response_carries_a_request_id(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get(CorrelateRequests::HEADER));
    }

    public function test_a_generated_id_is_a_uuid(): void
    {
        $id = $this->get('/')->headers->get(CorrelateRequests::HEADER);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id
        );
    }

    public function test_an_inbound_id_is_kept(): void
    {
        // So one identity survives a load balancer or a queue worker.
        $id = $this->withHeader(CorrelateRequests::HEADER, 'abc12345-def')
            ->get('/')
            ->headers->get(CorrelateRequests::HEADER);

        $this->assertSame('abc12345-def', $id);
    }

    public function test_a_hostile_inbound_id_is_replaced(): void
    {
        // The reason this does not simply pass the header through. A newline in an
        // id would let anyone inject a whole fake log line, and a quote would
        // break the response header.
        $injected = "abc\r\nX-Injected: yes";

        $id = $this->withHeader(CorrelateRequests::HEADER, $injected)
            ->get('/')
            ->headers->get(CorrelateRequests::HEADER);

        $this->assertNotSame($injected, $id);
        $this->assertFalse($this->get('/')->headers->has('X-Injected'));
    }

    public function test_an_over_long_inbound_id_is_replaced(): void
    {
        $id = $this->withHeader(CorrelateRequests::HEADER, str_repeat('a', 200))
            ->get('/')
            ->headers->get(CorrelateRequests::HEADER);

        $this->assertLessThanOrEqual(64, strlen($id));
    }

    public function test_a_short_inbound_id_is_replaced(): void
    {
        $id = $this->withHeader(CorrelateRequests::HEADER, 'abc')
            ->get('/')
            ->headers->get(CorrelateRequests::HEADER);

        $this->assertNotSame('abc', $id);
    }

    public function test_the_id_reaches_every_log_line_in_the_request(): void
    {
        // The point of the middleware: a Log:: call made anywhere while handling a
        // request carries the id, without the call site knowing it exists.
        //
        // Asserted by swapping the logger for a spy that records what it is handed,
        // because the alternative - Log::getContext() - is not a real method, and a
        // test that passes by asserting nothing about a nonexistent API is worse
        // than no test.
        $spy = new class
        {
            /** @var array<int, array<string, mixed>> */
            public array $contexts = [];

            public function withContext(array $context): self
            {
                $this->contexts[] = $context;

                return $this;
            }

            public function __call(string $method, array $arguments): self
            {
                return $this;
            }
        };

        Log::swap($spy);

        $this->get('/up')->assertOk();

        $this->assertNotEmpty($spy->contexts, 'The middleware never touched the log context.');
        $this->assertArrayHasKey(
            'request_id',
            $spy->contexts[0],
            'The request id was not added to the logging context.'
        );
    }

    /* ------------------------------------------------------------------ */

    /** Shared across the tickets of one test, reset per test by RefreshDatabase. */
    private ?TimeSlot $slot = null;

    private int $ticketCounter = 0;

    private function ticket(Service $service, ServiceWindow $window, string $status, array $overrides = []): Appointment
    {
        if (! $this->slot) {
            $start = now()->addDay()->setTime(9, 0);

            $this->slot = TimeSlot::create([
                'service_id' => $service->id,
                'service_window_id' => $window->id,
                'slot_date' => $start->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '09:30:00',
                'start_at' => $start->toDateTimeString(),
                'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
                'capacity' => 20,
                'booked_count' => 0,
                'is_open' => true,
            ]);
        }

        // queue_number is a 12-character column, so it cannot carry a uniqid().
        // A sequential number is also more honest here: nothing in these tests
        // depends on the number being unique beyond the column constraint.
        $this->ticketCounter++;

        return Appointment::create(array_merge([
            'reference_number' => 'GQ-M-'.substr(uniqid(), -8),
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $service->id,
            'time_slot_id' => $this->slot->id,
            'service_window_id' => $window->id,
            'status' => $status,
            'queue_number' => 'A-'.str_pad((string) $this->ticketCounter, 3, '0', STR_PAD_LEFT),
            'queue_date' => today()->toDateString(),
            'checked_in_at' => now(),
        ], $overrides));
    }
}
