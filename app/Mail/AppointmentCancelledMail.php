<?php

namespace App\Mail;

/**
 * Sent when an appointment is cancelled, releasing the slot back to other clients.
 */
class AppointmentCancelledMail extends AppointmentMail
{
    protected function mailSubject(): string
    {
        return $this->appointment->cancelledByOffice()
            ? 'Your booking was cancelled by the office - '.$this->appointment->reference_number
            : 'Your appointment has been cancelled - '.$this->appointment->reference_number;
    }

    protected function heading(): string
    {
        return $this->appointment->cancelledByOffice()
            ? 'Your booking was cancelled by the office'
            : 'Your appointment has been cancelled';
    }

    protected function intro(): string
    {
        $reason = filled($this->appointment->cancel_reason)
            ? ' Reason given: '.$this->appointment->cancel_reason.'.'
            : '';

        if ($this->appointment->cancelledByOffice()) {
            return 'We could not serve this booking, so it has been cancelled and the slot '
                .'released to another client. Nothing is held against you, and you can book '
                .'again whenever you like.'.$reason;
        }

        return 'Your booking has been cancelled and the slot released to another client.'.$reason;
    }

    protected function footnote(): string
    {
        return 'You can book another slot at any time from your GovQueue account. '
            .'This is an automated message from the Municipal Government of Tupi.';
    }

    protected function actionUrl(): ?string
    {
        return url('/services');
    }

    protected function actionLabel(): string
    {
        return 'Book another appointment';
    }
}
