<?php

namespace App\Contracts;

use App\Models\Appointment;
use App\Models\ServiceWindow;

/**
 * How a queue change is announced to whoever is watching a board.
 *
 * Exists to break a circular dependency. QueueService needs to announce the
 * changes it makes, and QueueBroadcaster needs to read the queue to work out a
 * client's position for the event payload - so the two needed each other, and
 * QueueService had to resolve the broadcaster lazily out of the container to avoid
 * infinite construction.
 *
 * That lazy lookup was working, but it hid the real problem: the two services had
 * no declared relationship, only a convention that QueueBroadcaster happens to
 * take a QueueService. Nothing stopped the next person from reaching deeper into
 * QueueService, or from QueueService depending on something else that circles back.
 *
 * This contract states the narrow direction that actually exists - the service
 * that mutates the queue announces; the thing that announces reads on its own
 * account. QueueService depends only on this interface, so the cycle cannot be
 * formed by accident, and QueueBroadcaster is free to be replaced by a fake in a
 * test without dragging the whole queue service in with it.
 *
 * Broadcasting is best-effort by contract: an implementation must never throw. A
 * lobby display that cannot update must not stop a client from being served.
 */
interface QueueAnnouncer
{
    /**
     * Announce that a window's queue changed.
     *
     * @param  string|null  $queueDate  defaults to today
     */
    public function queueChanged(ServiceWindow $window, ?string $queueDate = null): void;

    /**
     * Announce a ticket's new status, carrying the client's live position and wait.
     */
    public function appointmentChanged(Appointment $appointment): void;

    /**
     * Announce several tickets at once, for bulk operations.
     *
     * @param  iterable<int, Appointment>  $appointments
     */
    public function appointmentsChanged(iterable $appointments): void;

    /**
     * Is the announcer believed to be usable right now?
     *
     * False means recent failures, so the caller can explain a stale board rather
     * than leaving staff to assume the system is broken.
     */
    public function isHealthy(): bool;
}
