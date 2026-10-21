<?php

namespace App\Mail;

use App\Models\Appointment;

/**
 * Sent when the counter could not complete a client's transaction.
 *
 * The client was at the counter - they were served - and the service could not
 * be given, usually because a document the service needs was not brought. The
 * reason matters more here than anywhere else in this family: it is what the
 * client does next, and "not completed" on its own tells them nothing they
 * can act on.
 */
class AppointmentNotCompletedMail extends AppointmentMail
{
    public function __construct(
        Appointment $appointment,
        public readonly string $reason,
    ) {
        parent::__construct($appointment);
    }

    protected function mailSubject(): string
    {
        return 'Your transaction could not be completed - '.$this->appointment->service->name;
    }

    protected function heading(): string
    {
        return 'Your transaction could not be completed';
    }

    protected function intro(): string
    {
        return 'Good day '.$this->appointment->user->name
            .'. You were served at the counter, but the transaction could not be completed. '
            .'The reason: '.$this->reason
            .' Nothing was charged, and this does not count against you. You can book again.';
    }

    protected function detailRows(): array
    {
        $appointment = $this->appointment;

        return [
            'Queue number' => $appointment->queue_number ?? 'Assigned at the counter',
            'Reason' => $this->reason,
            'Service' => $appointment->service->name,
            'Window' => $appointment->window?->name ?? 'To be announced',
            'Reference' => (string) $appointment->reference_number,
        ];
    }

    protected function actionUrl(): ?string
    {
        return url('/my-appointments/'.$this->appointment->id);
    }

    protected function actionLabel(): string
    {
        return 'View my appointment';
    }

    protected function footnote(): string
    {
        return 'If you believe this is wrong, please speak to the staff at your window. '
            .'This is an automated message from the Municipal Government of Tupi.';
    }
}
