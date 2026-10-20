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

    /**
     * A walk-in was offered twice for the same person.
     *
     * Different from duplicateService(): that is a client double-booking
     * themselves online, which has a reschedule path. This is staff issuing two
     * tickets at one counter, so the message names the ticket already in the
     * queue and the fix is to skip or close it, not to reschedule it.
     */
    public static function duplicateWalkIn(Appointment $existing, string $matchedOn): self
    {
        return new self(
            ($matchedOn === 'contact'
                ? 'This contact number already has a waiting walk-in for this service'
                : 'This name already has a waiting walk-in for this service')
            .': '.$existing->queue_number.' ('.$existing->user->name.', joined '
            .$existing->checked_in_at?->format('g:i A').'). Skip or close that ticket first, or confirm the duplicate below.',
            $existing,
            'duplicate_walk_in',
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

    /**
     * The client has already arrived, so the ticket is not theirs to cancel.
     *
     * Says what to do about it rather than only what is not allowed, because
     * the person reading this is standing in the office and the next step is
     * obvious to a clerk but not to a resident.
     */
    public static function notCancellable(Appointment $existing): self
    {
        return new self(
            'You have already checked in for this appointment, so it cannot be cancelled online. '
            .'Please speak to the staff at your window.',
            $existing,
            'not_cancellable',
        );
    }
}
