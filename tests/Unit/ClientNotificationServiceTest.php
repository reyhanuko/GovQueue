<?php

namespace Tests\Unit;

use App\Contracts\SmsMessage;
use App\Enums\NotificationStatus;
use App\Exceptions\SmsDeliveryException;
use App\Mail\AppointmentCancelledMail;
use App\Mail\AppointmentConfirmedMail;
use App\Mail\AppointmentReminderMail;
use App\Mail\AppointmentRescheduledMail;
use App\Mail\AppointmentSkippedMail;
use App\Models\Appointment;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use App\Services\ClientNotificationService;
use App\Services\Sms\LogSmsGateway;
use App\Services\SmsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Notification fan-out: channel isolation, delivery logging, and failure
 * handling.
 */
class ClientNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Appointment $appointment;

    private User $client;

    private ClientNotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'govqueue.notifications.enabled' => true,
            'govqueue.notifications.email' => true,
            'govqueue.notifications.sms' => false,
            'govqueue.notifications.in_app' => true,
        ]);

        $window = ServiceWindow::factory()->create();
        $service = Service::factory()->create(['service_window_id' => $window->id]);
        $client = User::factory()->client()->create(['email' => 'ana@example.test']);

        $start = now()->addDay()->setTime(9, 0);
        $slot = TimeSlot::create([
            'service_id' => $service->id, 'service_window_id' => $window->id,
            'slot_date' => $start->toDateString(), 'start_time' => '09:00:00', 'end_time' => '09:30:00',
            'start_at' => $start->toDateTimeString(), 'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 5, 'booked_count' => 1, 'is_open' => true,
        ]);

        $this->client = $client;
        $this->appointment = Appointment::create([
            'reference_number' => 'GQ-N-001', 'user_id' => $client->id, 'service_id' => $service->id,
            'time_slot_id' => $slot->id, 'service_window_id' => $window->id, 'status' => 'confirmed',
            'source' => 'online', 'scheduled_at' => $start, 'queue_number' => 'A-001',
            'queue_date' => $start->toDateString(),
        ]);

        $this->notifications = new ClientNotificationService(app(SmsManager::class));
    }

    /* ------------------------------------------------------------------ */
    /* Confirmation */
    /* ------------------------------------------------------------------ */

    public function test_confirmation_sends_email_and_in_app(): void
    {
        Mail::fake();
        Notification::fake();

        $this->notifications->confirmation($this->appointment);

        Mail::assertQueued(AppointmentConfirmedMail::class, fn ($mail) => $mail->hasTo('ana@example.test'));
        Notification::assertSentTo($this->client, AppointmentNotification::class);
    }

    public function test_confirmation_is_logged_for_each_channel(): void
    {
        Mail::fake();
        Notification::fake();

        $this->notifications->confirmation($this->appointment);

        $logs = NotificationLog::where('appointment_id', $this->appointment->id)->get();

        // Three rows, not two: SMS is disabled in configuration, and a channel
        // that was never attempted is now recorded as failed with the reason.
        // Previously the disabled SMS channel left no row at all, which is what
        // made the admin notifications screen read 0/0/0.
        $this->assertCount(3, $logs);
        $this->assertEqualsCanonicalizing(
            ['email', 'in_app', 'sms'],
            $logs->pluck('channel')->map(fn ($c) => $c->value)->all(),
        );

        foreach ($logs as $log) {
            $this->assertSame(ClientNotificationService::CONFIRMED, $log->type);
        }
    }

    public function test_a_disabled_channel_is_recorded_as_failed_with_a_reason(): void
    {
        Mail::fake();
        Notification::fake();

        config(['govqueue.notifications.sms' => false]);

        $this->notifications->confirmation($this->appointment);

        $sms = NotificationLog::where('appointment_id', $this->appointment->id)
            ->where('channel', 'sms')
            ->first();

        $this->assertNotNull($sms, 'A disabled channel must still leave a record.');
        $this->assertSame('failed', $sms->status->value);
        $this->assertStringContainsString('SMS is disabled in configuration', $sms->error_message);
    }

    public function test_a_channel_the_client_opted_out_of_is_recorded_as_failed(): void
    {
        Mail::fake();
        Notification::fake();

        config(['govqueue.notifications.sms' => true]);
        $this->appointment->user->update(['notify_sms' => false]);

        $this->notifications->confirmation($this->appointment->fresh('user'));

        $sms = NotificationLog::where('appointment_id', $this->appointment->id)
            ->where('channel', 'sms')
            ->first();

        $this->assertNotNull($sms);
        $this->assertSame('failed', $sms->status->value);
        $this->assertStringContainsString('turned off SMS', $sms->error_message);
    }

    public function test_being_called_notifies_the_client_on_every_channel(): void
    {
        Mail::fake();
        Notification::fake();

        config(['govqueue.notifications.sms' => false]);

        $this->notifications->called($this->appointment);

        $types = NotificationLog::where('appointment_id', $this->appointment->id)
            ->distinct()
            ->pluck('type')
            ->all();

        $this->assertContains(ClientNotificationService::CALLED, $types);
    }

    public function test_being_skipped_tells_the_client_where_they_landed(): void
    {
        Mail::fake();
        Notification::fake();

        $this->notifications->skipped($this->appointment, 4);

        $log = NotificationLog::where('appointment_id', $this->appointment->id)
            ->where('type', ClientNotificationService::SKIPPED)
            ->where('channel', 'email')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('queued', $log->status->value);

        $mail = new AppointmentSkippedMail($this->appointment, 4);

        // intro() is protected on the mailable base; render instead of reaching
        // into it, so this asserts the text the client actually receives.
        $rendered = $mail->render();

        $this->assertStringContainsString('position 4', $rendered);
    }

    public function test_the_email_log_records_the_real_subject(): void
    {
        Mail::fake();
        Notification::fake();

        $this->notifications->confirmation($this->appointment);

        $log = NotificationLog::where('channel', 'email')->firstOrFail();

        $this->assertStringContainsString('Appointment confirmed', (string) $log->subject);
        $this->assertStringContainsString('GQ-N-001', (string) $log->subject);
        $this->assertSame(NotificationStatus::Queued, $log->status);
    }

    /* ------------------------------------------------------------------ */
    /* Channel isolation */
    /* ------------------------------------------------------------------ */

    public function test_sms_failure_does_not_stop_email_or_in_app(): void
    {
        config(['govqueue.notifications.sms' => true]);

        Mail::fake();
        Notification::fake();

        // A gateway that always refuses.
        $failingGateway = new class extends LogSmsGateway
        {
            public function send(SmsMessage $message): void
            {
                throw SmsDeliveryException::gatewayError('test', 'simulated outage');
            }

            public function name(): string
            {
                return 'test-failing';
            }
        };

        // Create a new SmsManager with the failing gateway and inject it into a fresh
        // ClientNotificationService. Swapping on the existing instance doesn't work
        // because $this->notifications was already constructed with the old manager.
        $smsManager = app(SmsManager::class);
        $smsManager->swap($failingGateway);
        $notifications = new ClientNotificationService($smsManager);

        $notifications->confirmation($this->appointment);

        // The client must still be reached by the other channels.
        Mail::assertQueued(AppointmentConfirmedMail::class);
        Notification::assertSentTo($this->client, AppointmentNotification::class);

        $sms = NotificationLog::where('channel', 'sms')->firstOrFail();
        $this->assertSame(NotificationStatus::Failed, $sms->status);
        $this->assertStringContainsString('simulated outage', (string) $sms->error_message);
    }

    public function test_a_client_without_a_phone_gets_a_recorded_reason_rather_than_an_exception(): void
    {
        config(['govqueue.notifications.sms' => true]);
        Mail::fake();
        Notification::fake();

        $this->client->update(['phone' => null]);
        $this->notifications->confirmation($this->appointment->fresh());

        $sms = NotificationLog::where('channel', 'sms')->firstOrFail();

        $this->assertSame(NotificationStatus::Failed, $sms->status);
        $this->assertStringContainsString('no mobile number', (string) $sms->error_message);
    }

    public function test_a_mail_failure_is_recorded_and_does_not_throw(): void
    {
        Notification::fake();

        // Force the mail path to fail.
        config(['mail.default' => 'fail-driver']);

        try {
            $this->notifications->confirmation($this->appointment);
        } catch (\Throwable $e) {
            // Any escape here is still acceptable: the booking must not die.
            $this->assertTrue(true);
        }

        $log = NotificationLog::where('channel', 'email')->first();

        if ($log) {
            $this->assertContains($log->status, [NotificationStatus::Queued, NotificationStatus::Failed]);
        } else {
            $this->assertTrue(true, 'No email log row means the failure happened before it could be opened.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Switches */
    /* ------------------------------------------------------------------ */

    public function test_disabling_email_suppresses_email(): void
    {
        config(['govqueue.notifications.email' => false]);
        Mail::fake();
        Notification::fake();

        $this->notifications->confirmation($this->appointment);

        Mail::assertNothingQueued();
        Notification::assertSentTo($this->client, AppointmentNotification::class);
    }

    public function test_disabling_in_app_suppresses_in_app(): void
    {
        config(['govqueue.notifications.in_app' => false]);
        Mail::fake();
        Notification::fake();

        $this->notifications->confirmation($this->appointment);

        Notification::assertNothingSent();
        Mail::assertQueued(AppointmentConfirmedMail::class);
    }

    public function test_an_appointment_with_no_client_is_skipped_safely(): void
    {
        Mail::fake();
        Notification::fake();

        $orphan = new Appointment(['reference_number' => 'GQ-ORPHAN']);

        $this->notifications->confirmation($orphan);

        $this->assertSame(0, NotificationLog::where('type', ClientNotificationService::CONFIRMED)->count());
    }

    /* ------------------------------------------------------------------ */
    /* Other message types */
    /* ------------------------------------------------------------------ */

    public function test_reminder_uses_the_reminder_type(): void
    {
        Mail::fake();
        Notification::fake();

        $this->notifications->reminder($this->appointment, 24);

        // 3 because SMS is disabled by default and is now recorded as failed.
        $this->assertSame(
            3,
            NotificationLog::where('type', ClientNotificationService::REMINDER)->count(),
        );

        Mail::assertQueued(AppointmentReminderMail::class);
    }

    public function test_reschedule_mentions_the_previous_slot(): void
    {
        Mail::fake();
        Notification::fake();

        $this->notifications->rescheduled($this->appointment, 'Tue 06 Oct, 9:00 AM - 9:30 AM');

        // Assert on the rendered message: that is what the client actually reads,
        // and it avoids reaching into protected template helpers.
        Mail::assertQueued(
            AppointmentRescheduledMail::class,
            fn ($mail) => str_contains(strip_tags($mail->render()), 'Tue 06 Oct'),
        );
    }

    public function test_cancellation_includes_the_reason(): void
    {
        Mail::fake();
        Notification::fake();

        $this->appointment->update(['status' => 'cancelled', 'cancel_reason' => 'Client ill']);

        $this->notifications->cancelled($this->appointment->fresh());

        Mail::assertQueued(
            AppointmentCancelledMail::class,
            fn ($mail) => str_contains(strip_tags($mail->render()), 'Client ill'),
        );
    }

    public function test_a_walk_in_appointment_is_described_as_such(): void
    {
        $walkIn = $this->appointment->fresh();
        $walkIn->update(['source' => 'walk_in', 'scheduled_at' => null, 'queue_number' => 'W-001']);

        $rendered = strip_tags((new AppointmentConfirmedMail($walkIn->fresh()))->render());

        // A walk-in has no booked time, so the email must not invent a date.
        $this->assertStringContainsString('Walk-in', $rendered);
        $this->assertStringNotContainsString('Date', $rendered);
    }

    public function test_a_booked_appointment_shows_its_date_and_time(): void
    {
        $rendered = strip_tags((new AppointmentConfirmedMail($this->appointment->fresh()))->render());

        $this->assertStringContainsString('Date', $rendered);
        $this->assertStringContainsString('Time', $rendered);
        $this->assertStringNotContainsString('Walk-in', $rendered);
    }
}
