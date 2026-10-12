<?php

namespace App\Mail;

/**
 * Sent when the client's ticket is called to the counter.
 *
 * The queue number is in the subject line on purpose: the whole point of this
 * message is that a client who is not watching the lobby board finds out their
 * number was called.
 */
class AppointmentCalledMail extends AppointmentMail
{
    protected function mailSubject(): string
    {
        return ($this->appointment->queue_number ? $this->appointment->queue_number.' - ' : '')
            .'Your number has been called - '.$this->appointment->service->name;
    }

    protected function heading(): string
    {
        return $this->appointment->queue_number
            ? 'Number '.$this->appointment->queue_number.' - please proceed'
            : 'Please proceed to the window';
    }

    protected function intro(): string
    {
        return 'Good day '.$this->appointment->user->name
            .'. Your number has been called. Please proceed to the window now.';
    }

    /** The window matters more than the scheduled time once you are being served. */
    protected function detailRows(): array
    {
        $appointment = $this->appointment;

        return [
            'Queue number' => $appointment->queue_number ?? 'Assigned at the counter',
            'Service' => $appointment->service->name,
            'Window' => $appointment->window?->name ?? 'To be announced',
            'Reference' => (string) $appointment->reference_number,
        ];
    }

    protected function footnote(): string
    {
        return 'If nobody is available at the window, please tell the staff at that window. '
            .'This is an automated message from the Municipal Government of Tupi.';
    }
}
