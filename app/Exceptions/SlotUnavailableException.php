<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when a booking cannot proceed because the slot is no longer available.
 *
 * Carries the slot so the controller can point the client at the nearest
 * alternative instead of showing a bare error.
 */
class SlotUnavailableException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($message);
    }

    public static function closed(): self
    {
        return new self('This time slot is no longer open for booking.', 'closed');
    }

    public static function full(): self
    {
        return new self('This time slot has just been filled by another client. Please choose another slot.', 'full');
    }

    public static function past(): self
    {
        return new self('This time slot has already started. Please choose a later slot.', 'past');
    }

    public static function notFound(): self
    {
        return new self('The selected time slot could not be found.', 'not_found');
    }

    public static function mismatchedService(): self
    {
        return new self('The selected time slot does not belong to this service.', 'mismatch');
    }

    public static function outsideHorizon(): self
    {
        return new self(
            'Bookings open up to '.config('govqueue.booking_horizon_days').' days in advance.',
            'outside_horizon',
        );
    }
}
