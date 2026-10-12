<?php

namespace App\Mail;

use App\Models\Appointment;

/**
 * Sent when a client is not at the window and is moved back in the queue.
 *
 * Necessary because a client who does not know they have moved back will keep
 * standing at a window that is by then serving somebody else.
 */
class AppointmentSkippedMail extends AppointmentMail
{
    public function __construct(public Appointment $appointment, private ?int $newPosition = null) {}

    protected function mailSubject(): string
    {
        return 'You moved back in the queue'
            .($this->appointment->queue_number ? ' - '.$this->appointment->queue_number : '')
            .' - '.$this->appointment->service->name;
    }

    protected function heading(): string
    {
        return 'You have moved back in the queue';
    }

    protected function intro(): string
    {
        return 'Good day '.$this->appointment->user->name
            .'. You were not at the window when your number was called, so you have been moved back'
            .($this->newPosition ? ' to about position '.$this->newPosition : '')
            .'. Please stay nearby so you do not miss your turn again.';
    }

    /** @return array<string, string> */
    protected function detailRows(): array
    {
        $appointment = $this->appointment;

        return array_filter([
            'Queue number' => $appointment->queue_number ?? 'Assigned at the counter',
            'Your position' => $this->newPosition ? 'About '.$this->newPosition : null,
            'Service' => $appointment->service->name,
            'Window' => $appointment->window?->name ?? 'To be announced',
            'Reference' => (string) $appointment->reference_number,
        ]);
    }

    protected function footnote(): string
    {
        return 'If you cannot wait any longer, please tell staff so your appointment can be closed for the day. '
            .'This is an automated message from the Municipal Government of Tupi.';
    }
}