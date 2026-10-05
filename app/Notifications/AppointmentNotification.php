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
        ];
    }

    public function title(): string
    {
        return match ($this->type) {
            ClientNotificationService::CONFIRMED => 'Appointment confirmed',
            ClientNotificationService::REMINDER => 'Appointment reminder',
            ClientNotificationService::RESCHEDULED => 'Appointment moved',
            ClientNotificationService::CANCELLED => 'Appointment cancelled',
            default => 'Appointment update',
        };
    }
}
