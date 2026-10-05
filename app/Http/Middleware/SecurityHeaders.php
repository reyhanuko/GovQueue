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
}
