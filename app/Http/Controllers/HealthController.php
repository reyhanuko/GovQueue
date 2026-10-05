<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\QueueBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Liveness and readiness, for a deployment.
 *
 * Answers one question a human or a supervisor needs before touching the system:
 * is it running, and if not, which part is broken?
 *
 * Deliberately unauthenticated so a load balancer or a plain `curl` can use it,
 * and deliberately shallow - it reports whether each dependency *answers*, never
 * what is in it. That matters because it exposes table names and version
 * numbers; a check that leaks the schema to the internet is a worse problem than
 * the outage it was meant to detect.
 *
 * Returns 503 when a dependency is unreachable so a monitor can act on the status
 * code alone.
 */
class HealthController extends Controller
{
    /** A dependency slower than this is reported as degraded, not failed. */
    private const SLOW_MS = 1000;

    public function __invoke(QueueBroadcaster $broadcaster): JsonResponse
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('select 1')),
            // Resolves the connection and reports which driver is configured. It
            // does not push a job, because a health check that enqueues work on
            // every poll would be its own source of load.
            'queue' => $this->probe(fn () => Queue::connection()),
            'time' => $this->probe(fn () => Carbon::now()),
            'cache' => $this->probe(fn () => cache()->put('govqueue:health', 1, 10)),
            'realtime' => $this->status($broadcaster->isHealthy(), 'The lobby display is not updating.'),
        ];

        $healthy = ! collect($checks)->contains(fn ($c) => $c['status'] === 'fail');

        return response()->json([
            'status' => $healthy ? 'ok' : 'fail',
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'time' => Carbon::now()->toIso8601String(),
            // Useful in a report, harmless in aggregate: a count, not contents.
            'appointments_today' => $this->safeCount(),
            'checks' => $checks,
            'queue_driver' => config('queue.default'),
            'broadcast_driver' => config('broadcasting.default'),
        ], $healthy ? 200 : 503, [], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Run one dependency check and describe the outcome.
     *
     * @param  callable():mixed  $probe
     * @return array{status: string, ms: float, note?: string}
     */
    private function probe(callable $probe): array
    {
        $start = microtime(true);

        try {
            $probe();
            $ms = (microtime(true) - $start) * 1000;

            return [
                'status' => $ms > self::SLOW_MS ? 'slow' : 'ok',
                'ms' => round($ms, 1),
            ];
        } catch (Throwable $e) {
            // The exception type, never its message: a connection string or a
            // hostname would otherwise be served to the internet.
            return [
                'status' => 'fail',
                'ms' => round((microtime(true) - $start) * 1000, 1),
                'note' => class_basename($e).' thrown.',
            ];
        }
    }

    /**
     * A non-fatal observation, such as realtime being down.
     *
     * 'warn' rather than 'fail' because the office can still book and serve with
     * the lobby display offline; only the display is stale. It must not take the
     * deployment out of rotation.
     *
     * The note is omitted when there is nothing wrong, so a healthy report does
     * not carry alarming text.
     *
     * @return array{status: string, ms: float, note?: string}
     */
    private function status(bool $healthy, string $note): array
    {
        if ($healthy) {
            return ['status' => 'ok', 'ms' => 0.0];
        }

        return ['status' => 'warn', 'ms' => 0.0, 'note' => $note];
    }

    private function safeCount(): ?int
    {
        try {
            return Appointment::whereDate('queue_date', today())->count();
        } catch (Throwable) {
            // The database check has already reported the failure; do not double up.
            return null;
        }
    }
}
