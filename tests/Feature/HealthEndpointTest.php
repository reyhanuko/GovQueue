<?php

namespace Tests\Feature;

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
        // Pre-boot the session before mocking the DB, so the session middleware
        // (which needs a real DB connection to start) completes successfully.
        // Only then do we swap the DB to simulate a connection failure for the
        // controller's own health-check query.
        $this->get('/up'); // warm up session

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
        // Pre-boot the session before mocking the DB (see test above).
        $this->get('/up');

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

    public function test_it_reports_no_business_metrics(): void
    {
        $body = $this->get('/up')->assertOk()->getContent();

        // This endpoint used to carry appointments_today. It leaked no personal
        // data, but it is a business figure served to anyone who asks, and the
        // volume of a day is the kind of thing an unauthorised reader can use to
        // tell how busy the office is. A monitor only ever needed "does it
        // answer", which the checks block already gives.
        $this->assertStringNotContainsString('appointments_today', $body);
    }

    public function test_it_reports_no_infrastructure_detail(): void
    {
        $body = $this->get('/up')->assertOk()->getContent();

        // Driver names and the app environment were justified as "useful in an
        // incident report", which is true and irrelevant: anything reading this
        // endpoint is a load balancer or a curl. Naming the live infrastructure
        // narrows an attacker's search for free, and an operator with shell access
        // can read config directly.
        // "database", "cache" and "queue" are deliberately absent: they are the
        // names of the checks themselves, and a monitor needs to know which
        // dependency answered. What must not appear is the *implementation* behind
        // them - driver, version, environment.
        foreach ([
            'queue_driver', 'broadcast_driver', 'environment', 'app',
            'govqueue', 'reverb', 'mysql', 'redis', 'sqlite',
            'APP_ENV', 'production', 'staging', 'Exception', 'SQLSTATE',
        ] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $body,
                "The health endpoint disclosed \"{$leak}\"."
            );
        }
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

    public function test_the_response_carries_nothing_beyond_status_time_and_checks(): void
    {
        // Pins the whole surface rather than three fields, so a future diagnostic
        // added here has to be a deliberate decision in a review instead of a
        // helpful extra that ships by default.
        $this->get('/up')
            ->assertOk()
            ->assertJsonStructure(['status', 'time', 'checks'])
            ->assertJsonCount(3);
    }
}
