<?php

namespace App\Mail;

/**
 * Sent when the hourly close-out closes a ticket that nobody resolved.
 *
 * The client has to be told. A ticket that is closed silently is a ticket the
 * client finds out about by looking at a screen that says their appointment
 * was closed and their place was given away - which is how a resident finds
 * out that they are recorded as a no-show, and how the office finds out that
 * the record is wrong.
 */
class AppointmentNoShowMail extends AppointmentMail
{
    protected function mailSubject(): string
    {
        return 'Your appointment has been closed out - '.$this->appointment->reference_number;
    }

    protected function heading(): string
    {
        return 'Your appointment has been closed out';
    }

    protected function intro(): string
    {
        return 'Your appointment has been closed out and recorded as a no-show. '
            .'The slot was closed after it ended without anyone completing it, '
            .'and the appointment was not cancelled before then.';
    }

    protected function footnote(): string
    {
        return 'If you believe this is wrong, please contact the municipal office. '
            .'This is an automated message from the Municipal Government of Tupi.';
    }

    protected function actionUrl(): ?string
    {
        return url('/my-appointments');
    }

    protected function actionLabel(): string
    {
        return 'See my appointments';
    }
}
