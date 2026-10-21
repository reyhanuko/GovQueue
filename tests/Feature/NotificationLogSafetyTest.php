<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\User;
use App\Services\ClientNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * What the notification log shows staff, and what it promises clients.
 *
 * Two things were wrong, both reported from the admin screen.
 *
 * A failed notification stored the exception message on the row, and the screen
 * renders that column. For a QueryException the message is the whole SQL
 * statement including its binds - so a staff member with access to
 * /admin/notifications was shown a query, and that query could carry a client's
 * name, birthdate, phone number or disability status.
 *
 * And with a filter applied, an empty result still said "No notifications have
 * been attempted yet" - which tells an administrator the system has never
 * tried, when the truth is that nothing matches what they asked for. That
 * sends people looking for a fault that is not there.
 */
class NotificationLogSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Appointment $appointment;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $service = Service::factory()->create(['service_window_id' => $window->id]);

        $this->client = User::factory()->client()->create([
            'email' => 'ana@example.test',
            'phone' => '09181234567',
        ]);

        $this->appointment = Appointment::factory()->create([
            'user_id' => $this->client->id,
            'service_id' => $service->id,
            'service_window_id' => $window->id,
            'status' => 'confirmed',
        ]);
    }

    /**
     * The regression: an exception message reaching an administrator's screen.
     *
     * The message used is a realistic QueryException, because "did not appear"
     * is exactly what a real one looks like once Laravel has wrapped it.
     */
    public function test_a_failure_shows_staff_a_reason_not_the_exception(): void
    {
        $log = NotificationLog::create([
            'appointment_id' => $this->appointment->id,
            'user_id' => $this->client->id,
            'channel' => 'email',
            'type' => ClientNotificationService::CONFIRMED,
            'destination' => 'ana@example.test',
            'status' => 'queued',
        ]);

        $this->recordFailure($log, $this->queryException());

        $log->refresh();

        // The row is safe to render. A real QueryException's message is the whole
        // statement plus its binds, and the binds are where a client's details
        // would be.
        $this->assertStringNotContainsString('SQLSTATE', $log->error_message);
        $this->assertStringNotContainsString('select * from', $log->error_message);
        $this->assertStringNotContainsString('notifications', $log->error_message);
        $this->assertStringContainsString('application log', $log->error_message);
    }

    public function test_the_technical_reason_still_reaches_the_application_log(): void
    {
        Log::spy();

        $log = NotificationLog::create([
            'appointment_id' => $this->appointment->id,
            'user_id' => $this->client->id,
            'channel' => 'email',
            'type' => ClientNotificationService::CONFIRMED,
            'destination' => 'ana@example.test',
            'status' => 'queued',
        ]);

        $this->recordFailure($log, $this->queryException());

        // Moving the detail from the screen to the log is the whole point. If it
        // went nowhere instead, the change would only have made failures harder
        // to diagnose while looking like a fix.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'SQLSTATE'));
    }

    /**
     * Drive the private failure handler directly.
     *
     * By reflection rather than by adding a public test-only method to the
     * service: a test seam in production code is a method somebody will later
     * call from somewhere it should not be reachable.
     */
    private function recordFailure(NotificationLog $log, \Throwable $e): void
    {
        $method = new \ReflectionMethod(ClientNotificationService::class, 'fail');
        $method->setAccessible(true);
        $method->invoke(app(ClientNotificationService::class), $log, $e);
    }

    /** A realistic QueryException, because that is what actually arrived. */
    private function queryException(): QueryException
    {
        return new QueryException(
            'mysql',
            'select * from appointments where user_id = ?',
            [7],
            new \Exception("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'govqueue.notifications' doesn't exist"),
        );
    }

    public function test_the_admin_screen_never_shows_sql(): void
    {
        $admin = User::factory()->admin()->create();

        NotificationLog::create([
            'appointment_id' => $this->appointment->id,
            'user_id' => $this->client->id,
            'channel' => 'email',
            'type' => ClientNotificationService::CONFIRMED,
            'destination' => 'ana@example.test',
            'status' => 'failed',
            'error_message' => 'This message could not be sent. The technical reason is in the application log.',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $html);
        $this->assertStringNotContainsString('insert into', $html);
        $this->assertStringContainsString('could not be sent', $html);
    }

    public function test_an_unfiltered_empty_log_says_nothing_has_been_attempted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('No notifications have been attempted yet');
    }

    /**
     * The empty state that sends people hunting for a fault.
     *
     * Filtering to something with no matches is not the same as never having
     * sent anything, and the screen used to claim the latter.
     */
    public function test_a_filtered_empty_result_does_not_claim_nothing_was_attempted(): void
    {
        $admin = User::factory()->admin()->create();

        NotificationLog::create([
            'appointment_id' => $this->appointment->id,
            'user_id' => $this->client->id,
            'channel' => 'email',
            'type' => ClientNotificationService::CONFIRMED,
            'destination' => 'ana@example.test',
            'status' => 'queued',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.notifications.index', ['status' => 'failed']))
            ->assertOk()
            ->assertSee('No notifications match this filter')
            ->assertDontSee('No notifications have been attempted yet');
    }

    /**
     * A promise the system cannot keep.
     *
     * SMS is off by default, so a client ticking "Text me" would store the
     * preference, see it saved, and never be texted - while the profile said
     * messages were on.
     */
    public function test_the_sms_toggle_is_not_offered_when_sms_is_off(): void
    {
        config(['govqueue.notifications.sms' => false]);

        $this->actingAs($this->client)
            ->get(route('client.profile.edit'))
            ->assertOk()
            // On the input, not the label. "Text me" is a substring of
            // "Text messages are not available", which is the very copy that
            // replaces it - so asserting the label is gone by text fails for
            // exactly the right reason.
            ->assertDontSee('name="notify_sms"', escape: false)
            ->assertSee('Text messages are not available');
    }

    public function test_the_sms_toggle_is_offered_when_sms_is_on(): void
    {
        config([
            'govqueue.notifications.sms' => true,
            'govqueue.notifications.enabled' => true,
        ]);

        $this->actingAs($this->client)
            ->get(route('client.profile.edit'))
            ->assertOk()
            ->assertSee('Text me')
            ->assertDontSee('Text messages are not available');
    }

    /** A crafted post must not set a preference the system will not honour. */
    public function test_the_sms_preference_cannot_be_set_through_a_crafted_post(): void
    {
        config(['govqueue.notifications.sms' => false]);

        $this->actingAs($this->client)
            ->from(route('client.profile.edit'))
            ->put(route('client.profile.notifications'), [
                'notify_email' => 1,
                'notify_sms' => 1,
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $this->client->fresh()->notify_sms);
    }
}
