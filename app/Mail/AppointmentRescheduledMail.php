<?php

namespace App\Mail;

use App\Models\Appointment;

/**
 * Sent when the client moves an appointment to a different slot.
 */
class AppointmentRescheduledMail extends AppointmentMail
{
    public function __construct(Appointment $appointment, public readonly ?string $previousSlot = null)
    {
        parent::__construct($appointment);
    }

    protected function mailSubject(): string
    {
        return 'Your appointment has been moved - '.$this->appointment->reference_number;
    }

    protected function heading(): string
    {
        return 'Your appointment has been moved';
    }

    protected function intro(): string
    {
        $from = $this->previousSlot
            ? 'Your appointment was previously booked for '.$this->previousSlot.'. '
            : '';

        return $from.'It is now confirmed for the date and time below, and your queue number has been reissued.';
    }

    protected function actionUrl(): ?string
    {
        return url('/my-appointments/'.$this->appointment->id);
    }
}
