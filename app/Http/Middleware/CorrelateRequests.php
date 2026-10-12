<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every request with an id, so one client's action can be followed through
 * the log.
 *
 * Without this, tracing a single booking means searching the log by timestamp and
 * hoping the entries are close together - which fails exactly when it matters,
 * because the interesting cases are the slow ones and the ones that failed after
 * several other things had already happened.
 *
 * The id is honoured when it arrives from upstream and generated when it does not,
 * so a request that crosses a load balancer or a queue worker keeps one identity
 * across both halves. A request header is attacker-controlled, so an inbound value
 * is accepted only if it looks like an id: anything containing a character that
 * could break a log line - a newline being the obvious one - is discarded rather
 * than trusted. That is the whole reason this does not simply pass the header
 * through.
 *
 * The id is put in the logging context so every subsequent Log:: call in the
 * request carries it, and echoed back as a response header so a client, a support
 * note, or a staff member can quote it.
 */
class CorrelateRequests
{
    /** Response header, so a caller can quote the id back to an administrator. */
    public const HEADER = 'X-Request-Id';

    /** Inbound header a proxy or queue worker sets to carry the id across. */
    private const INBOUND = 'X-Request-Id';

    /** Attribute the id is stored under, for anything that needs it directly. */
    public const ATTRIBUTE = 'govqueue_request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolveId($request);

        $request->attributes->set(self::ATTRIBUTE, $id);

        // Applies to every Log:: call made while handling this request, so no
        // individual call site has to remember to pass it.
        Log::withContext(['request_id' => $id]);

        $response = $next($request);

        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /**
     * Reuse a well-formed inbound id, otherwise mint one.
     */
    private function resolveId(Request $request): string
    {
        $inbound = $request->header(self::INBOUND);

        if (is_string($inbound) && $this->isAcceptable($inbound)) {
            return $inbound;
        }

        return (string) Str::uuid();
    }

    /**
     * Is this safe to put in a log line and an HTTP header?
     *
     * Deliberately strict: lowercase hex and dashes, 8 to 64 characters. That
     * admits what nginx, a proxy, or a worker would send while excluding every
     * character that could forge a log entry or split a header. Guessing here would
     * be the wrong instinct - an inbound id is convenience, never identity, so
     * there is no cost to being wrong and a real cost to being lenient.
     */
    private function isAcceptable(string $value): bool
    {
        return (bool) preg_match('/^[a-z0-9-]{8,64}$/', $value);
    }
}
