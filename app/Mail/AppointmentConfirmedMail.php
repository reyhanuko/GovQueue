<?php

namespace App\Mail;

/**
 * Sent the moment a booking is confirmed.
 */
class AppointmentConfirmedMail extends AppointmentMail
{
    protected function mailSubject(): string
    {
        return 'Appointment confirmed - '.$this->appointment->service->name
            .' ('.$this->appointment->reference_number.')';
    }

    protected function heading(): string
    {
        return 'Your appointment is confirmed';
    }

    protected function intro(): string
    {
        return 'Good day '.$this->appointment->user->name.'. Your booking has been recorded and your place '
            .'in the queue is reserved. Your queue number is below.';
    }

    protected function actionUrl(): ?string
    {
        return url('/my-appointments/'.$this->appointment->id);
    }
}
