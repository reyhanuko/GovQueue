<?php

namespace App\Exceptions;

use App\Models\Appointment;
use Exception;

/**
 * Raised when a client tries to book something that overlaps what they already
 * hold: a second live booking for the same service, or an overlapping window
 * with a different service.
 *
 * Carries the existing appointment so the UI can offer to reschedule it rather
 * than dead-ending the client.
 */
class AppointmentConflictException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?Appointment $conflicting = null,
        public readonly string $reason = 'duplicate',
    ) {
        parent::__construct($message);
    }

    public static function duplicateService(Appointment $existing): self
    {
        return new self(
            'You already have a live booking for this service on '.$existing->scheduledDateLabel().'. '
            .'Cancel or reschedule it before booking another slot.',
            $existing,
            'duplicate_service',
        );
    }

    public static function overlappingSlot(Appointment $existing): self
    {
        return new self(
            'You already have an appointment at '.$existing->scheduledTimeLabel().' on '
            .$existing->scheduledDateLabel().'. Please pick a time that does not overlap.',
            $existing,
            'overlapping_slot',
        );
    }

    public static function notReschedulable(Appointment $existing): self
    {
        return new self(
            'That appointment can no longer be changed. Please contact the municipal office.',
            $existing,
            'not_reschedulable',
        );
    }
}
