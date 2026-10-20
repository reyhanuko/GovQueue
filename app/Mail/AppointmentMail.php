<?php

namespace App\Mail;

use App\Enums\AppointmentSource;
use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Base for every client notification email.
 *
 * Implements ShouldQueue via the queued-mail layer: a client booking must not
 * wait on the SMTP connection, and a slow mail server must never fail a booking.
 * Delivery is therefore best-effort and is recorded in notification_logs.
 */
abstract class AppointmentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Appointment $appointment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject(),
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('mail.from.name'),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.appointment',
            with: [
                'appointment' => $this->appointment,
                'title' => $this->heading(),
                'intro' => $this->intro(),
                'rows' => $this->detailRows(),
                'actionUrl' => $this->actionUrl(),
                'actionLabel' => $this->actionLabel(),
                'footnote' => $this->footnote(),
                'office' => config('govqueue.office'),
            ],
        );
    }

    abstract protected function mailSubject(): string;

    abstract protected function heading(): string;

    abstract protected function intro(): string;

    /** @return array<string, string> */
    protected function detailRows(): array
    {
        $appointment = $this->appointment;

        // A walk-in has no booked time, so it must not be described as if it did.
        // Saying "Date: Walk-in" next to "Time: As arrived" read as a mistake to
        // the client.
        $rows = [
            'Reference' => (string) $appointment->reference_number,
            'Service' => $appointment->service->name.($appointment->window ? ' - '.$appointment->window->name : ''),
        ];

        if ($this->isWalkIn()) {
            $rows['Booking type'] = 'Walk-in (no fixed time)';
            $rows['Queue number'] = $appointment->queue_number ?? 'Assigned at the counter';
        } else {
            $rows['Date'] = $appointment->scheduledDateLabel();
            $rows['Time'] = $appointment->scheduledTimeLabel();
            $rows['Queue number'] = $appointment->queue_number ?? 'Assigned at the counter';
        }

        /*
         * The fee, while there is one to pay and the booking still stands.
         *
         * The booking screen says what the service costs and that it is
         * payable at the counter, and then the client is told to arrive early
         * and bring their documents - but not told to bring the money. A
         * resident who is told to bring a document and is not told to bring
         * the fee arrives at the counter without it.
         *
         * Not on a cancelled booking, which is the one case where there is
         * nothing left to pay.
         */
        if ($appointment->service->fee > 0 && ! $appointment->status->isClosed()) {
            $rows['Fee'] = '₱'.number_format((float) $appointment->service->fee, 2).' payable at the counter';
        }

        return $rows;
    }

    /**
     * Was this ticket issued on arrival rather than booked?
     */
    protected function isWalkIn(): bool
    {
        return $this->appointment->source === AppointmentSource::WalkIn
            || $this->appointment->scheduled_at === null;
    }

    protected function actionUrl(): ?string
    {
        return null;
    }

    protected function actionLabel(): string
    {
        return 'View my appointment';
    }

    protected function footnote(): string
    {
        return 'Please arrive 15 minutes early and bring the documents listed for this service. '
            .'This is an automated message from the Municipal Government of Tupi.';
    }
}
