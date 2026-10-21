<?php

namespace App\Enums;

/**
 * Lifecycle of a single appointment / queue ticket.
 *
 * Transitions are enforced in App\Services\QueueService, not here, so that the
 * legal rules about who may move a ticket stay in one reviewable place.
 */
enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Serving = 'serving';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Skipped = 'skipped';
    case NotCompleted = 'not_completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::CheckedIn => 'Checked in',
            self::Serving => 'Being served',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No show',
            self::Skipped => 'Skipped',
            self::NotCompleted => 'Not completed',
        };
    }

    /**
     * The ticket still exists.
     *
     * The client's word for this is "active" - their appointment list is
     * headed "Upcoming & active" - and it is what the list, the live board and
     * the "still worth showing" rule all mean.
     *
     * It is not the same question as "is this client in the queue?", which
     * isInLine() answers. A ticket being served is not in the line: that
     * client is standing at the counter. It is very much still live, and the
     * client needs to be able to see that it is.
     */
    public function isActive(): bool
    {
        return ! $this->isClosed();
    }

    /**
     * The statuses that mean a ticket is still live.
     *
     * One place to ask, because the list was written out four times with
     * three different answers, and a query that disagreed with the page about
     * what a client still holds is how a client ends up with two places in
     * one queue.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isActive()),
        ));
    }

    /** Ticket has reached a terminal state. */
    public function isClosed(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Cancelled,
            self::NoShow,
            self::NotCompleted,
        ], true);
    }

    /**
     * The client is physically at the office for this ticket.
     *
     * A ticket that is checked in or being served has somebody standing at a
     * window. What happens to it from here is a decision for the staff at that
     * window, not for the client on their phone - which is why a client may
     * not cancel one of these.
     */
    public function isAtTheOffice(): bool
    {
        return in_array($this, [self::CheckedIn, self::Serving], true);
    }

    /**
     * The ticket is holding a place in the line.
     *
     * Confirmed and pending clients have not arrived yet, so they are not in
     * the queue; completed, cancelled and no-shows are done with.
     */
    public function isInLine(): bool
    {
        return in_array($this, [self::CheckedIn, self::Skipped], true);
    }

    /**
     * Statuses this ticket may move to next.
     *
     * Two edges exist purely so staff can act naturally at the counter:
     *  - Serving -> NoShow, because a client can walk away mid-transaction.
     *  - Skipped  -> Skipped, because "not ready" pressed twice must not error.
     *
     * And two more, because a client who is at the counter can be turned away
     * for a reason that is neither a completion nor an absence:
     *  - Serving -> NotCompleted, because the service needs a document the
     *    client did not bring, and the client was there.
     *  - Skipped  -> NotCompleted, for the same reason, once they have come
     *    back to the counter and the document is still missing.
     */
    public function canTransitionTo(self $target): bool
    {
        $allowed = [
            self::Pending->value => [self::Confirmed->value, self::Cancelled->value],
            self::Confirmed->value => [self::CheckedIn->value, self::Cancelled->value, self::NoShow->value],
            self::CheckedIn->value => [self::Serving->value, self::NoShow->value, self::Skipped->value, self::Cancelled->value],
            self::Serving->value => [self::Completed->value, self::Skipped->value, self::NoShow->value, self::NotCompleted->value],
            self::Skipped->value => [
                self::CheckedIn->value, self::Serving->value,
                self::NoShow->value, self::Skipped->value, self::Cancelled->value,
                self::NotCompleted->value,
            ],
            self::Completed->value => [],
            self::Cancelled->value => [],
            self::NoShow->value => [],
            self::NotCompleted->value => [],
        ];

        return in_array($target->value, $allowed[$this->value] ?? [], true);
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-slate-100 text-slate-700',
            self::Confirmed => 'bg-blue-100 text-blue-700',
            self::CheckedIn => 'bg-indigo-100 text-indigo-700',
            self::Serving => 'bg-amber-100 text-amber-800',
            self::Completed => 'bg-emerald-100 text-emerald-700',
            self::Cancelled => 'bg-slate-200 text-slate-600',
            self::NoShow => 'bg-rose-100 text-rose-700',
            self::Skipped => 'bg-orange-100 text-orange-700',
            self::NotCompleted => 'bg-purple-100 text-purple-700',
        };
    }
}
