<?php

namespace App\Mail;

use App\Models\Appointment;

/**
 * Sent ahead of the appointment, 24 hours and 1 hour before by default.
 */
class AppointmentReminderMail extends AppointmentMail
{
    public function __construct(Appointment $appointment, public readonly int $hoursBefore)
    {
        parent::__construct($appointment);
    }

    protected function mailSubject(): string
    {
        return $this->hoursBefore >= 24
            ? 'Reminder: your appointment is tomorrow'
            : 'Reminder: your appointment is coming up';
    }

    protected function heading(): string
    {
        return $this->hoursBefore >= 24
            ? 'Your appointment is tomorrow'
            : 'Your appointment is in about an hour';
    }

    protected function intro(): string
    {
        return $this->hoursBefore >= 24
            ? 'This is a reminder that you have an appointment at the municipal office tomorrow.'
            : 'Your appointment is coming up shortly. Please make your way to the office.';
    }

    protected function detailRows(): array
    {
        return parent::detailRows() + [
            'Arrive by' => $this->appointment->scheduled_at
                ? $this->appointment->scheduled_at->subMinutes(15)->format('g:i A')
                : '-',
        ];
    }

    protected function actionUrl(): ?string
    {
        return url('/my-appointments/'.$this->appointment->id);
    }
}
