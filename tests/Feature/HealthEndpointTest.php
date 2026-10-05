<?php

namespace Tests\Feature;

use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The deployment health endpoint.
 *
 * It is deliberately unauthenticated so a load balancer or a plain curl can use it,
 * which makes it the most exposed URL in the application. The rule that follows
 * from that: it reports whether each dependency *answers*, never what is inside
 * it. A health endpoint that leaked table names, driver credentials or framework
 * versions would be a worse problem than the outage it exists to detect.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_ok_when_everything_works(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonPath('checks.queue.status', 'ok');
    }

    public function test_a_guest_can_reach_it(): void
    {
        // A load balancer has no credentials. Requiring a session would make the
        // check useless for its only real caller.
        $this->get('/up')->assertOk();
    }

    public function test_it_reports_503_when_the_database_is_unreachable(): void
    {
        // Simulated by breaking the connection rather than stopping MySQL, so the
        // suite never depends on a service being down.
        DB::shouldReceive('select')->andThrow(new \RuntimeException(
            'SQLSTATE[HY000] [2002] Connection refused for user secret_user at 10.0.0.5'
        ));

        $response = $this->get('/up');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'fail');
        $response->assertJsonPath('checks.database.status', 'fail');
    }

    public function test_it_never_echoes_a_dependency_exception_message(): void
    {
        // The message is where credentials, hostnames and ports live.
        DB::shouldReceive('select')->andThrow(new \RuntimeException(
            'SQLSTATE[HY000] [2002] Connection refused for user secret_user at 10.0.0.5'
        ));

        $body = $this->get('/up')->assertStatus(503)->getContent();

        $this->assertStringNotContainsString('secret_user', $body);
        $this->assertStringNotContainsString('10.0.0.5', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_it_does_not_disclose_the_schema(): void
    {
        $body = $this->get('/up')->assertOk()->getContent();

        // Real table and infrastructure identifiers. "appointments_today" is a
        // deliberate key holding a single integer, asserted separately below.
        foreach ([
            'time_slots', 'password_reset_tokens', 'slot_templates', 'audit_logs',
            'notification_logs', 'users', 'govqueue_test', 'Laravel', 'mysql',
        ] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $body,
                "The health endpoint disclosed \"{$leak}\"."
            );
        }
    }

    public function test_the_appointment_count_is_a_number_and_not_a_record(): void
    {
        $body = $this->get('/up')->assertOk()->getContent();

        // The key is allowed; the value must be a bare integer.
        $this->assertMatchesRegularExpression(
            '/"appointments_today":\s*\d+/',
            $body
        );
    }

    public function test_it_reports_how_many_appointments_are_in_today(): void
    {
        // An aggregate count is useful in an incident report and harmless: it
        // reveals no personal data.
        Appointment::factory()->count(3)->create([
            'queue_date' => today()->toDateString(),
        ]);

        $this->get('/up')
            ->assertOk()
            ->assertJsonPath('appointments_today', 3);
    }

    public function test_a_stale_lobby_display_is_a_warning_not_a_failure(): void
    {
        // The office can still book and serve with the display offline, so this
        // must not take the deployment out of rotation.
        Cache::put(
            'govqueue:broadcast-breaker:'.config('broadcasting.default'),
            ['failed_at' => now()->toIso8601String()],
            30
        );

        $response = $this->get('/up');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checks.realtime.status', 'warn');
    }

    public function test_a_healthy_report_carries_no_alarming_note(): void
    {
        // An earlier version attached "the lobby display is not updating" to every
        // response, healthy or not.
        $body = $this->get('/up')->assertOk()->getContent();

        $this->assertStringNotContainsString('not updating', $body);
    }

    public function test_it_names_the_configured_drivers(): void
    {
        // Knowing which queue and broadcast backend is live is the first question
        // asked during an incident, and neither value is sensitive.
        $this->get('/up')
            ->assertOk()
            ->assertJsonStructure(['queue_driver', 'broadcast_driver']);
    }
}
