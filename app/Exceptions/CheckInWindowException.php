<?php

namespace App\Exceptions;

use App\Models\Appointment;
use Exception;
use Illuminate\Support\Carbon;

/**
 * Raised when a client tries to check in outside the window the office allows
 * (decision D7).
 *
 * A client may join the line from half an hour before their slot starts until
 * a quarter of an hour after it ends. Earlier than that they have not arrived
 * for this slot; later than that the counter has moved on, and a ticket
 * checked in then is a person standing in a queue for a slot that has already
 * been served.
 *
 * The exception exists so the console can say when the window is, rather than
 * showing a clerk a refusal they cannot explain to the person in front of
 * them.
 */
class CheckInWindowException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?Appointment $appointment = null,
        public readonly ?Carbon $opensAt = null,
        public readonly ?Carbon $closesAt = null,
    ) {
        parent::__construct($message);
    }

    public static function outsideWindow(
        Appointment $appointment,
        Carbon $opensAt,
        Carbon $closesAt,
    ): self {
        return new self(
            'That slot is for '.$appointment->scheduledTimeLabel().' on '
            .$appointment->scheduledDateLabel().'. Check-in is open from '
            .$opensAt->format('g:i A').' to '.$closesAt->format('g:i A').'.',
            $appointment,
            $opensAt,
            $closesAt,
        );
    }
}
