<?php

namespace App\Contracts;

use App\Models\Appointment;
use App\Models\ServiceWindow;
use Illuminate\Support\Collection;

/**
 * Read-only access to a window's queue.
 *
 * Extracted from QueueService to break a genuine circular dependency.
 *
 * The shape of the problem: announcing a queue change needs the client's position
 * and estimated wait, so the announcer has to read the queue. But QueueService is
 * what produces those changes, so it also has to announce them. The two therefore
 * needed each other, and no amount of interface indirection removes that - a
 * binding of QueueAnnouncer to QueueBroadcaster just moved the cycle into the
 * container and recursed until memory ran out. Verified, not theorised.
 *
 * What does remove it is separating the two halves by direction rather than by
 * name. Reads do not need the mutator and writes do not need the reads:
 *
 *     QueueService  -> QueueAnnouncer -> QueueBroadcaster -> QueueReader
 *     QueueService  -> QueueReader
 *
 * Nothing points back up that chain, so the graph is acyclic. It is also a truer
 * statement of the domain: these methods answer "what does this window look like
 * right now", which is a different question from "move this ticket to serving", and
 * the lobby display, the client's own page, and the reports all need the first
 * without any ability to do the second.
 *
 * QueueService keeps these methods as thin delegations, so callers that already
 * hold a QueueService do not change and the two can never drift apart.
 */
interface QueueReader
{
    /**
     * Everyone physically at the counter, in service order.
     *
     * @return Collection<int, Appointment>
     */
    public function waitingFor(ServiceWindow $window, ?string $queueDate = null): Collection;

    /**
     * The ticket currently at the window, if any.
     */
    public function currentlyServing(ServiceWindow $window, ?string $queueDate = null): ?Appointment;

    /**
     * Clients who have a booking on this day but have not checked in yet.
     *
     * @return Collection<int, Appointment>
     */
    public function expectedButNotArrived(ServiceWindow $window, ?string $queueDate = null): Collection;

    /**
     * Minutes each waiting client should expect to wait, keyed by appointment id.
     *
     * $perClientMinutes lets a caller that already knows the window's average pass
     * it in, so the figure on screen cannot disagree with the estimates beneath it.
     *
     * @param  Collection<int, Appointment>  $queue
     * @return array<int, int|null>
     */
    public function estimateWaits(ServiceWindow $window, Collection $queue, ?float $perClientMinutes = null): array;

    /**
     * Measured average minutes per client for a window, over recent history.
     */
    public function averageServiceMinutes(ServiceWindow $window): float;

    /**
     * Where this client sits in the line, and how long they should expect to wait.
     *
     * @return array{0:?int, 1:?int} position (1-based) and estimated minutes
     */
    public function locate(Appointment $appointment): array;
}
