<?php

namespace App\Mail;

use App\Models\Appointment;

/**
 * Sent when staff verify a client's claimed statutory priority.
 *
 * The only message in this family that reports a change to their place rather
 * than to their ticket. Verification reorders the queue, so the client is being
 * told something they would otherwise only discover by walking up and finding
 * somebody else served first.
 *
 * $types is passed in rather than derived, because QueueService has just
 * resolved the granted categories from the appointment's claims and this must
 * describe the decision that was actually made - not a re-read of the claims,
 * which could differ if the model were stale.
 */
class AppointmentPriorityVerifiedMail extends AppointmentMail
{
    public function __construct(
        Appointment $appointment,
        public readonly string $types,
    ) {
        parent::__construct($appointment);
    }

    protected function mailSubject(): string
    {
        return 'Priority verified - '.$this->appointment->service->name;
    }

    protected function heading(): string
    {
        return 'Your priority has been verified';
    }

    protected function intro(): string
    {
        return 'Good day '.$this->appointment->user->name
            .'. Staff have checked your claim and confirmed your '.$this->types
            .' priority. You may now be served ahead of clients who have no '
            .'verified priority. The order shown on the lobby display is the order you will be served in.';
    }

    /**
     * The window, not the scheduled time.
     *
     * As with the "called" message, once priority is granted what matters is
     * which counter and which number, not what time the booking was made for.
     */
    protected function detailRows(): array
    {
        $appointment = $this->appointment;

        return [
            'Queue number' => $appointment->queue_number ?? 'Assigned at the counter',
            'Priority' => $this->types,
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
        return 'If you believe this is wrong, please speak to the staff at your window before your '
            .'number is called. This is an automated message from the Municipal Government of Tupi.';
    }
}
