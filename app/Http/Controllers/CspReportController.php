<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Collects Content-Security-Policy violation reports.
 *
 * This exists so the policy can be shipped in report-only mode first. The browser
 * posts what it blocked here whenever the policy is violated, and the resulting log
 * is the only trustworthy way to learn which origins the live deployment actually
 * needs - guessing them is how an enforced policy ends up breaking the lobby
 * display or the Reverb socket.
 *
 * It must never echo anything back: the report body is entirely attacker-supplied,
 * so reflecting it into a response would turn this endpoint into a reflector for
 * whatever a scanner sends.
 */
class CspReportController extends Controller
{
    /**
     * Accept a violation report and record it.
     *
     * The report itself is the JSON body; older agents nest it under
     * "csp-report". Both shapes are accepted because which one arrives depends on
     * the browser, and dropping half of them would hide exactly the violations the
     * rollout is meant to surface.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        $report = $payload['csp-report'] ?? $payload;

        // Only the fields needed to identify the offending directive are kept.
        // document-uri and the violated directive are what tell us which origin to
        // allow; the full blocked body would only add noise and client-supplied text.
        Log::warning('GovQueue CSP violation', [
            'document_uri' => Str::limit((string) ($report['document-uri'] ?? $report['documentURL'] ?? ''), 300),
            'violated_directive' => Str::limit((string) ($report['violated-directive'] ?? $report['effectiveDirective'] ?? ''), 200),
            'blocked_uri' => Str::limit((string) ($report['blocked-uri'] ?? $report['blockedURL'] ?? ''), 300),
            'source_file' => Str::limit((string) ($report['source-file'] ?? $report['sourceFile'] ?? ''), 200),
            'line' => $report['line-number'] ?? $report['lineNumber'] ?? null,
            'ip' => $request->ip(),
        ]);

        /*
         * 204 rather than 200 with a body: the browser fires this from
         * report-uri on every violation and needs a cheap acknowledgement.
         * An empty body also guarantees nothing attacker-supplied is reflected.
         */
        return response()->json(null, 204);
    }
}
