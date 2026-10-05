<?php

namespace App\Exceptions;

use App\Models\Appointment;
use Exception;

/**
 * Raised when staff attempt a queue transition that the lifecycle does not
 * allow.
 *
 * The allowed transitions live in AppointmentStatus::canTransitionTo() so the
 * legal rules stay in one place; this exception just turns an illegal attempt
 * into something the staff console can explain rather than a raw 500.
 */
class InvalidQueueTransitionException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?Appointment $appointment = null,
        public readonly string $from = '',
        public readonly string $to = '',
    ) {
        parent::__construct($message);
    }

    public static function notAllowed(Appointment $appointment, string $to): self
    {
        return new self(
            'An appointment that is '.strtolower($appointment->status->label())
            .' cannot be moved to '.strtolower(str_replace('_', ' ', $to)).'.',
            $appointment,
            $appointment->status->value,
            $to,
        );
    }

    public static function alreadyClosed(Appointment $appointment): self
    {
        return new self(
            'This appointment is already '.strtolower($appointment->status->label()).' and cannot be changed.',
            $appointment,
            $appointment->status->value,
            '',
        );
    }
}
