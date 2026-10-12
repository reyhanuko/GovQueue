<?php

namespace App\Services;

use App\Contracts\SmsMessage;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationStatus;
use App\Exceptions\SmsDeliveryException;
use App\Mail\AppointmentCalledMail;
use App\Mail\AppointmentCancelledMail;
use App\Mail\AppointmentConfirmedMail;
use App\Mail\AppointmentPriorityVerifiedMail;
use App\Mail\AppointmentReminderMail;
use App\Mail\AppointmentRescheduledMail;
use App\Mail\AppointmentSkippedMail;
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

    public const CALLED = 'appointment.called';

    public const SKIPPED = 'appointment.skipped';

    public const PRIORITY_VERIFIED = 'appointment.priority_verified';

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

    /**
     * The client has been called to the counter.
     *
     * This is the message that makes the queue board worth having: a client who
     * is not watching the lobby can still get to the window because their phone
     * told them their number was called.
     */
    public function called(Appointment $appointment): void
    {
        $this->fanOut(
            $appointment,
            self::CALLED,
            fn () => new AppointmentCalledMail($appointment),
            'Your number '.$appointment->queue_number.' has been called. Please proceed to the window.',
        );
    }

    /**
     * The client was not at the window when called and has been moved back.
     *
     * Sent because a client who does not know they have been moved back will
     * keep standing at a window that is now serving someone else.
     */
    public function skipped(Appointment $appointment, ?int $position = null): void
    {
        $this->fanOut(
            $appointment,
            self::SKIPPED,
            fn () => new AppointmentSkippedMail($appointment, $position),
            $position
                ? 'You were not ready, so you moved back to position '.$position.'. Please stay nearby.'
                : 'You were not ready and have moved back in the queue. Please stay nearby.',
        );
    }

    /**
     * A staff member has verified a client's claimed statutory priority.
     *
     * Sent because this decision moves people. The queue reorders on it, and
     * the client is the only party who cannot see the reorder happening - the
     * same argument as called() and skipped(), applied to the one action that
     * was missing it.
     *
     * There is deliberately no counterpart for a *refused* claim. Emailing
     * someone that their disability or senior-citizen claim was rejected is a
     * judgement for the office, not a default: some offices will have a clerk
     * explain it in person, some will not. Until that is decided, a refusal is
     * spoken, not emailed, and nothing here should imply otherwise.
     */
    public function priorityVerified(Appointment $appointment, string $types): void
    {
        $this->fanOut(
            $appointment,
            self::PRIORITY_VERIFIED,
            fn () => new AppointmentPriorityVerifiedMail($appointment, $types),
            'Your priority ('.$types.') has been verified. You may now be served sooner.',
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

        // Every branch below records a row, including the branches that decline to
        // send. A channel that is switched off or unconfigured used to be
        // skipped silently, which left the admin log showing nothing at all
        // and gave staff no way to tell "no message was attempted" from
        // "the message failed".
        if (! config('govqueue.notifications.email')) {
            $this->skipChannel($appointment, $client, NotificationChannelType::Email, $type, $client->email, 'Email is disabled in configuration (GOVQUEUE_EMAIL_ENABLED).');
        } elseif (! $client->wantsEmail()) {
            $this->skipChannel($appointment, $client, NotificationChannelType::Email, $type, $client->email, 'Client has turned off email notifications.');
        } else {
            $this->sendEmail($appointment, $client, $type, $mailFactory);
        }

        // Opting out suppresses the send entirely. A client with no number on file
        // still gets a delivery row explaining why, which is how staff find out
        // they cannot text someone.
        if (! config('govqueue.notifications.sms')) {
            $this->skipChannel($appointment, $client, NotificationChannelType::Sms, $type, $client->phone, 'SMS is disabled in configuration (GOVQUEUE_SMS_ENABLED).');
        } elseif (! $client->wantsSms()) {
            $this->skipChannel($appointment, $client, NotificationChannelType::Sms, $type, $client->phone, 'Client has turned off SMS notifications.');
        } else {
            $this->sendSms($appointment, $client, $type, $smsBody);
        }

        // In-app is never opt-out: it is the record the client sees at the office,
        // it stays inside the system, and without it a client who turned everything
        // else off would have no way to learn their appointment had moved.
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

    /**
     * Record an attempt that was never made, and why.
     *
     * Status is Failed rather than skipped-with-no-row on purpose: the office
     * does not promise delivery, so staff need to see every message the system
     * did not manage to reach a client. Leaving these channels silent is what
     * made the notifications screen read 0/0/0 while bookings were succeeding.
     */
    private function skipChannel(
        Appointment $appointment,
        User $client,
        NotificationChannelType $channel,
        string $type,
        ?string $destination,
        string $reason,
    ): void {
        $this->openLog($appointment, $client, $channel, $type, $destination)
            ->markFailed($reason);
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
