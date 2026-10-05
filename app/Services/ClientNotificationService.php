<?php

namespace App\Services;

use App\Contracts\SmsMessage;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationStatus;
use App\Exceptions\SmsDeliveryException;
use App\Mail\AppointmentCancelledMail;
use App\Mail\AppointmentConfirmedMail;
use App\Mail\AppointmentReminderMail;
use App\Mail\AppointmentRescheduledMail;
use App\Models\Appointment;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The one place client notifications are dispatched.
 *
 * Everything funnels through here so that:
 *  - each channel can fail independently without taking the others (or the
 *    booking that triggered it) down;
 *  - every attempt is written to notification_logs, which is what lets staff see
 *    that a client was not reached - important because the project's stated scope
 *    makes no promise of delivery;
 *  - messages are queued, not sent inline.
 */
class ClientNotificationService
{
    /** Notification type identifiers, used as the log's `type` column. */
    public const CONFIRMED = 'appointment.confirmed';

    public const REMINDER = 'appointment.reminder';

    public const RESCHEDULED = 'appointment.rescheduled';

    public const CANCELLED = 'appointment.cancelled';

    public function __construct(private SmsManager $sms) {}

    /* ------------------------------------------------------------------ */
    /* Public API */
    /* ------------------------------------------------------------------ */

    public function confirmation(Appointment $appointment): void
    {
        $this->fanOut(
            $appointment,
            self::CONFIRMED,
            fn () => new AppointmentConfirmedMail($appointment),
            $this->defaultBody($appointment, 'Your appointment is confirmed'),
        );
    }

    public function reminder(Appointment $appointment, int $hoursBefore): void
    {
        $this->fanOut(
            $appointment,
            self::REMINDER,
            fn () => new AppointmentReminderMail($appointment, $hoursBefore),
            $hoursBefore >= 24
                ? 'Reminder: your GovQueue appointment is tomorrow.'
                : 'Reminder: your GovQueue appointment is in about an hour.',
        );
    }

    public function rescheduled(Appointment $appointment, ?string $previousSlot = null): void
    {
        $this->fanOut(
            $appointment,
            self::RESCHEDULED,
            fn () => new AppointmentRescheduledMail($appointment, $previousSlot),
            $this->defaultBody($appointment, 'Your GovQueue appointment has been moved'),
        );
    }

    public function cancelled(Appointment $appointment): void
    {
        $this->fanOut(
            $appointment,
            self::CANCELLED,
            fn () => new AppointmentCancelledMail($appointment),
            $this->defaultBody($appointment, 'Your GovQueue appointment has been cancelled'),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Fan-out */
    /* ------------------------------------------------------------------ */

    /**
     * Dispatch across every enabled channel, isolating failures.
     *
     * @param  callable(): Mailable  $mailFactory
     */
    private function fanOut(
        Appointment $appointment,
        string $type,
        callable $mailFactory,
        string $smsBody,
    ): void {
        $client = $appointment->user;

        if (! $client) {
            Log::warning('Notification skipped: appointment has no client.', [
                'appointment_id' => $appointment->id,
            ]);

            return;
        }

        if (config('govqueue.notifications.email')) {
            $this->sendEmail($appointment, $client, $type, $mailFactory);
        }

        if (config('govqueue.notifications.sms')) {
            $this->sendSms($appointment, $client, $type, $smsBody);
        }

        if (config('govqueue.notifications.in_app')) {
            $this->sendInApp($appointment, $client, $type);
        }
    }

    private function sendEmail(
        Appointment $appointment,
        User $client,
        string $type,
        callable $mailFactory,
    ): void {
        $log = $this->openLog($appointment, $client, NotificationChannelType::Email, $type, $client->email);

        try {
            // Build the mailable once: calling the factory twice would create two
            // different instances and the logged subject could disagree with the
            // message that was actually queued.
            $mailable = $mailFactory();

            Mail::to($client->email)->queue($mailable);

            $log->update([
                'status' => NotificationStatus::Queued,
                'subject' => $mailable->envelope()->subject,
                'attempts' => 1,
            ]);
        } catch (Throwable $e) {
            $this->fail($log, $e);
        }
    }

    private function sendSms(
        Appointment $appointment,
        User $client,
        string $type,
        string $body,
    ): void {
        $destination = $client->phone;

        if (blank($destination)) {
            // Not an error worth an exception: record it so staff can see the
            // client has no number on file.
            $this->openLog($appointment, $client, NotificationChannelType::Sms, $type, null)
                ->markFailed('Client has no mobile number on file.');

            return;
        }

        $log = $this->openLog($appointment, $client, NotificationChannelType::Sms, $type, $destination);

        try {
            $this->sms->send(new SmsMessage([$destination], $this->smsBody($appointment, $body)));

            $log->markSent();
        } catch (SmsDeliveryException $e) {
            $log->markFailed($e->getMessage());
        } catch (Throwable $e) {
            $this->fail($log, $e);
        }
    }

    private function sendInApp(Appointment $appointment, User $client, string $type): void
    {
        $log = $this->openLog(
            $appointment,
            $client,
            NotificationChannelType::InApp,
            $type,
            'in-app:'.$client->id,
        );

        try {
            Notification::send($client, new AppointmentNotification($appointment, $type));

            $log->markSent();
        } catch (Throwable $e) {
            $this->fail($log, $e);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function openLog(
        Appointment $appointment,
        User $client,
        NotificationChannelType $channel,
        string $type,
        ?string $destination,
    ): NotificationLog {
        return NotificationLog::create([
            'appointment_id' => $appointment->id,
            'user_id' => $client->id,
            'channel' => $channel,
            'type' => $type,
            'destination' => $destination,
            'status' => NotificationStatus::Queued,
            'attempts' => 0,
        ]);
    }

    private function fail(NotificationLog $log, Throwable $e): void
    {
        $log->markFailed($e->getMessage());

        Log::warning('GovQueue notification failed: '.$e->getMessage(), [
            'log_id' => $log->id,
            'channel' => $log->channel->value,
        ]);
    }

    /**
     * SMS bodies are length-constrained, so the full detail lives in the email.
     */
    private function smsBody(Appointment $appointment, string $headline): string
    {
        $lines = array_filter([
            $headline,
            'Ref '.$appointment->reference_number,
            trim($appointment->service->name),
            $this->scheduleLine($appointment),
            $appointment->queue_number ? 'Queue no. '.$appointment->queue_number : null,
        ]);

        $body = implode("\n", $lines);

        // 160 GSM characters per segment; keep it to a single segment where we can.
        if (mb_strlen($body) > 160) {
            $body = mb_substr($body, 0, 157).'...';
        }

        return $body;
    }

    private function defaultBody(Appointment $appointment, string $headline): string
    {
        return $headline;
    }

    /**
     * One line describing when the appointment is, or "Walk-in" when it has no
     * booked time.
     */
    private function scheduleLine(Appointment $appointment): string
    {
        if (! $appointment->scheduled_at) {
            return 'Walk-in';
        }

        return $appointment->scheduled_at->format('D d M, g:i A');
    }
}
