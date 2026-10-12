<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Services\ClientNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The in-app copy of every client notification.
 *
 * Gives a client who cannot receive email or SMS (or who simply does not check
 * either) a durable record inside their own account.
 */
class AppointmentNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Appointment $appointment,
        public string $type,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Not used: the same content goes out through the queued mailables.
        return (new MailMessage)->line($this->title());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title(),
            'appointment_id' => $this->appointment->id,
            'reference' => $this->appointment->reference_number,
            'queue_number' => $this->appointment->queue_number,
            'scheduled_at' => $this->appointment->scheduled_at?->toIso8601String(),
            'status' => $this->appointment->status->value,
            // Written at dispatch time because the inbox has only this payload:
            // re-reading the appointment later would show today's state, not
            // what the message said when it was sent.
            'summary' => $this->summary(),
        ];
    }

    /**
     * The summary line for a stored notification payload.
     *
     * Static and array-driven because the inbox only ever has the array - it
     * deliberately does not re-join the appointment, so a notification about a
     * since-deleted or since-changed appointment still reads correctly.
     *
     * @param  array<string, mixed>  $data
     */
    public static function summaryFor(array $data): string
    {
        return (string) ($data['summary'] ?? 'An update from the office.');
    }

    public function title(): string
    {
        return match ($this->type) {
            ClientNotificationService::CONFIRMED => 'Appointment confirmed',
            ClientNotificationService::REMINDER => 'Appointment reminder',
            ClientNotificationService::RESCHEDULED => 'Appointment moved',
            ClientNotificationService::CANCELLED => 'Appointment cancelled',
            ClientNotificationService::CALLED => 'Your number has been called',
            ClientNotificationService::SKIPPED => 'You moved back in the queue',
            default => 'Appointment update',
        };
    }

    /**
     * One line of context, so an inbox entry explains itself without the reader
     * having to open the appointment.
     */
    public function summary(): string
    {
        $appointment = $this->appointment;

        $what = trim($appointment->service->name ?? 'your appointment');

        return match ($this->type) {
            ClientNotificationService::CALLED => 'Proceed to '.$appointment->window?->name
                .' now. Your number is '.($appointment->queue_number ?? 'waiting to be called').'.',
            ClientNotificationService::SKIPPED => 'Stay nearby. You were not at the window when called.',
            ClientNotificationService::CONFIRMED => 'Booked for '.$appointment->scheduledDateLabel()
                .' at '.$appointment->scheduledTimeLabel().'. Reference '.$appointment->reference_number.'.',
            ClientNotificationService::REMINDER => 'Due '.$appointment->scheduledDateLabel()
                .' at '.$appointment->scheduledTimeLabel().'.',
            ClientNotificationService::RESCHEDULED, ClientNotificationService::CANCELLED => $what
                .' - reference '.$appointment->reference_number.'.',
            default => $what,
        };
    }
}
