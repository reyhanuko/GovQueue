<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The Content-Security-Policy, and the collector that makes enforcing it safe.
 *
 * The policy ships report-only on purpose. An enforced CSP that is missing one
 * origin breaks the lobby display or the Reverb socket, which for this office is
 * worse than having no CSP at all - the board goes dark and the reason is not
 * obvious. Violations are posted to /csp-report instead, so the origins to allow
 * are read off real traffic rather than guessed, and enforcement is a config
 * change once that log has been read.
 */
class CspHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_policy_is_sent_on_public_pages(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('Content-Security-Policy-Report-Only');
    }

    public function test_the_policy_closes_the_dangerous_directions(): void
    {
        $csp = $this->get('/')->assertOk()->headers->get('Content-Security-Policy-Report-Only');

        // object-src 'none' blocks plugin-based execution outright; base-uri 'self'
        // blocks an injected <base> tag from rewriting every relative URL on the
        // page; frame-ancestors duplicates X-Frame-Options for browsers that
        // honour CSP over the legacy header.
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_the_policy_permits_the_websocket_the_display_needs(): void
    {
        $csp = $this->get('/')->assertOk()->headers->get('Content-Security-Policy-Report-Only');

        // The lobby display stops updating without this, and a stale display is
        // exactly the failure the whole realtime design exists to prevent.
        $this->assertStringContainsString('wss:', $csp);
        $this->assertStringContainsString('ws:', $csp);
    }

    public function test_it_is_report_only_until_a_deployment_has_verified_it(): void
    {
        // Asserted explicitly so shipping it enforced on the first try is a failing
        // test rather than a broken waiting room.
        $response = $this->get('/')->assertOk();

        $this->assertFalse(
            $response->headers->has('Content-Security-Policy'),
            'The enforced CSP shipped without a deployment having reviewed its violations.'
        );
    }

    public function test_a_violation_is_logged_and_never_reflected_back(): void
    {
        Log::spy();

        $response = $this->postJson('/csp-report', [
            'csp-report' => [
                'document-uri' => 'https://govqueue.test/queue-display',
                'violated-directive' => 'script-src-elem',
                'blocked-uri' => 'https://cdn.example.com/widget.js',
            ],
        ]);

        // 204 with no body, not 200 with one. The entire payload is
        // attacker-supplied, so echoing any of it back would make this endpoint a
        // reflector for whatever a scanner sends at it.
        $response->assertNoContent();
        $this->assertSame('', $response->getContent());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($context['blocked_uri'] ?? '', 'cdn.example.com'));
    }

    public function test_the_collector_accepts_the_flat_payload_shape_too(): void
    {
        // Whether the report is nested under "csp-report" depends on the browser.
        // Rejecting one shape would hide exactly the violations the rollout exists
        // to surface, so both are accepted.
        Log::spy();

        $this->postJson('/csp-report', [
            'document-uri' => 'https://govqueue.test/',
            'violated-directive' => 'img-src',
            'blocked-uri' => 'https://tracker.example.net/pixel.gif',
        ])->assertNoContent();

        Log::shouldHaveReceived('warning');
    }

    public function test_the_collector_survives_an_empty_or_non_json_body(): void
    {
        // Anything can POST here. A 500 would be reported as an application
        // outage, and the endpoint is supposed to be the opposite of one.
        $this->postJson('/csp-report', [])->assertNoContent();

        // Sent raw rather than through post() so the body really is a string that
        // is not JSON, which is what a scanner or a broken browser sends.
        $this->call(
            'POST',
            '/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'not json at all'
        )->assertNoContent();
    }

    public function test_the_collector_cannot_be_used_to_flood_the_log_file(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->postJson('/csp-report', ['csp-report' => ['document-uri' => 'https://x.test/']]);
        }

        $this->postJson('/csp-report', ['csp-report' => ['document-uri' => 'https://x.test/']])
            ->assertStatus(429);
    }

    public function test_the_collector_needs_no_session(): void
    {
        // The browser posts it without credentials; requiring auth would mean the
        // violations never arrive at all.
        $this->assertGuest();

        $this->postJson('/csp-report', ['csp-report' => ['document-uri' => 'https://x.test/']])
            ->assertNoContent();
    }
}
