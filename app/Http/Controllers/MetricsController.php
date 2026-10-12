<?php

namespace App\Http\Controllers;

use App\Services\MetricsCollector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Prometheus scrape endpoint.
 *
 * Token-gated rather than public and rather than behind the admin login.
 *
 * Public would be simplest to scrape, but the figures here are real operational
 * data - how busy each counter is, how many clients are waiting right now - and
 * this endpoint is unauthenticated by design so a scraper needs no session. Behind
 * the admin login does not work either, because Prometheus cannot carry a cookie.
 *
 * So: a shared secret in the environment, compared with hash_equals. Fails closed
 * when METRICS_TOKEN is unset, which is the property that matters - a deployment
 * that forgets to configure it gets a 404 rather than an open window onto the
 * office's queue volumes.
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request, MetricsCollector $collector): Response
    {
        $expected = (string) config('app.metrics_token', '');

        // Unconfigured means closed. Deliberately indistinguishable from a wrong
        // token so a misconfiguration cannot be probed for.
        if ($expected === '' || ! $this->authorised($request, $expected)) {
            return response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        return response($collector->render(), SymfonyResponse::HTTP_OK, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    /**
     * Accept the token from the header Prometheus supports, or as a query
     * parameter for a manual check from a browser.
     *
     * hash_equals rather than === because a plain comparison returns as soon as it
     * finds a difference, which is enough to recover the token one character at a
     * time from response timing.
     */
    private function authorised(Request $request, string $expected): bool
    {
        $supplied = (string) (
            $request->header('X-Metrics-Token')
            ?? $request->query('token')
            ?? ''
        );

        return $supplied !== '' && hash_equals($expected, $supplied);
    }
}
