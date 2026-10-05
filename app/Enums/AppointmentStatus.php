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
        };
    }

    /** Ticket still occupying a place in the queue. */
    public function isActive(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Confirmed,
            self::CheckedIn,
            self::Skipped,
        ], true);
    }

    /** Ticket has reached a terminal state. */
    public function isClosed(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Cancelled,
            self::NoShow,
        ], true);
    }

    /** Whether the client physically needs to be at the office for this state. */
    public function countsAsWalkIn(): bool
    {
        return in_array($this, [self::CheckedIn, self::Serving], true);
    }

    /**
     * Statuses this ticket may move to next.
     *
     * Two edges exist purely so staff can act naturally at the counter:
     *  - Serving -> NoShow, because a client can walk away mid-transaction.
     *  - Skipped  -> Skipped, because "not ready" pressed twice must not error.
     */
    public function canTransitionTo(self $target): bool
    {
        $allowed = [
            self::Pending->value => [self::Confirmed->value, self::Cancelled->value],
            self::Confirmed->value => [self::CheckedIn->value, self::Cancelled->value, self::NoShow->value],
            self::CheckedIn->value => [self::Serving->value, self::NoShow->value, self::Skipped->value, self::Cancelled->value],
            self::Serving->value => [self::Completed->value, self::Skipped->value, self::NoShow->value],
            self::Skipped->value => [
                self::CheckedIn->value, self::Serving->value,
                self::NoShow->value, self::Skipped->value, self::Cancelled->value,
            ],
            self::Completed->value => [],
            self::Cancelled->value => [],
            self::NoShow->value => [],
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
        };
    }
}
