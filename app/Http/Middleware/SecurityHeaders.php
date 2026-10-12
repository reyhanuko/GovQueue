<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser hardening applied to every web response.
 *
 * GovQueue stores residents' names, birthdates, contact numbers, and disability
 * status, so the framework's default of sending no security headers at all is
 * not good enough for this system.
 *
 * X-XSS-Protection is deliberately set to "0": the legacy auditor is deprecated
 * and, in some browsers, enabling it introduces vulnerabilities rather than
 * preventing them. Modern protection comes from the CSP-style controls and from
 * never rendering unescaped user data.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-XSS-Protection' => '0',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            $this->cspHeader() => $this->csp(),
        ];

        foreach ($headers as $name => $value) {
            // setHeader() overwrites, so this also fixes a header a controller
            // may have set to something weaker.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Content-Security-Policy, served report-only until the office's third-party
     * origins are known.
     *
     * Report-only rather than enforced on purpose: an enforced policy that misses
     * one origin silently breaks the lobby display or the WebSocket connection,
     * which is a far worse outcome than an unenforced policy. Violations are
     * posted to /csp-report and logged, so the exact origins to allow can be read
     * off real traffic instead of guessed - then flip
     * GOVQUEUE_CSP_ENFORCE=true.
     *
     * 'unsafe-inline' appears in script-src/style-src because Livewire and Reverb
     * inject inline bootstrap payloads. Removing it requires nonces on every
     * blade view, which is real work and is deliberately not done blind.
     */
    private function csp(): string
    {
        $directives = [
            "default-src 'self'",
            // ws: is required for the Reverb socket in local development, where the
            // socket is not served over TLS.
            "connect-src 'self' ws: wss:",
            "font-src 'self' data:",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "img-src 'self' data:",
            "object-src 'none'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "base-uri 'self'",
            'report-uri /csp-report',
        ];

        return implode('; ', $directives);
    }

    /**
     * The header name the policy is delivered under.
     *
     * Two names rather than one so the rollout is a config change: report-only
     * until violations have been reviewed, enforced afterwards.
     */
    private function cspHeader(): string
    {
        return filter_var(env('GOVQUEUE_CSP_ENFORCE', false), FILTER_VALIDATE_BOOLEAN)
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';
    }
}
