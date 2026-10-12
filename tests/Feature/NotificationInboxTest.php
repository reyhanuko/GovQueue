<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use App\Notifications\OpsNotification;
use App\Services\ClientNotificationService;
use App\Services\OpsNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The in-app inbox, shared by clients, staff and administrators.
 *
 * The inbox is one screen, so the risk is that it leaks: a message belonging to
 * one account must never be readable or deletable through another's session, and
 * a client's appointment must not be reachable by id alone. Those are the cases
 * that matter here, alongside each role actually receiving something.
 *
 * The `notifications` table did not exist until Sprint 12 - every dispatch was
 * being swallowed by ClientNotificationService's per-channel error handling, so
 * bookings worked and clients silently received nothing in-app. The first test
 * below is therefore about the table being usable at all.
 */
class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'INBOX-SVC',
            'name' => 'Inbox Service',
        ]);
    }

    private function appointmentFor(User $client): Appointment
    {
        $date = today()->toDateString();

        // firstOrCreate rather than create: several tests book two clients into
        // the same morning, and time_slots has a unique key on
        // (service_id, slot_date, start_at).
        $slot = TimeSlot::firstOrCreate(
            [
                'service_id' => $this->service->id,
                'slot_date' => $date,
                'start_at' => $date.' 09:00:00',
            ],
            [
                'service_window_id' => $this->window->id,
                'start_time' => '09:00:00',
                'end_time' => '09:30:00',
                'end_at' => $date.' 09:30:00',
                'capacity' => 5,
                'booked_count' => 1,
                'is_open' => true,
            ],
        );

        return Appointment::factory()->create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'user_id' => $client->id,
            'status' => 'confirmed',
            'queue_date' => $date,
            'scheduled_at' => $date.' 09:00:00',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Delivery */
    /* ------------------------------------------------------------------ */

    public function test_a_booking_writes_a_message_into_the_clients_inbox(): void
    {
        $client = User::factory()->client()->create();

        app(ClientNotificationService::class)->confirmation($this->appointmentFor($client));

        $notification = $client->fresh()->notifications()->sole();

        $this->assertSame(AppointmentNotification::class, $notification->type);
        $this->assertSame('Appointment confirmed', $notification->data['title']);
        $this->assertNull($notification->read_at);
    }

    public function test_the_message_says_what_happened_without_being_opened(): void
    {
        $client = User::factory()->client()->create();
        $appointment = $this->appointmentFor($client);

        app(ClientNotificationService::class)->called($appointment);

        $data = $client->fresh()->notifications()->sole()->data;

        // Written at dispatch, from the payload alone: re-reading the
        // appointment in the inbox would show today's state, not what the
        // message said when it was sent.
        $this->assertStringContainsString('called', $data['summary']);
        $this->assertSame(
            $data['summary'],
            AppointmentNotification::summaryFor($data),
        );
    }

    /* ------------------------------------------------------------------ */
    /* The screen */
    /* ------------------------------------------------------------------ */

    public function test_every_role_can_open_the_inbox(): void
    {
        foreach ([
            User::factory()->client()->create(),
            User::factory()->staff()->create(['service_window_id' => $this->window->id]),
            User::factory()->admin()->create(),
        ] as $user) {
            $this->actingAs($user)->get('/messages')->assertOk();
        }
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/messages')->assertRedirect(route('login'));
    }

    public function test_the_inbox_shows_only_your_own_messages(): void
    {
        $mine = User::factory()->client()->create();
        $theirs = User::factory()->client()->create();

        app(ClientNotificationService::class)->confirmation($this->appointmentFor($mine));
        app(ClientNotificationService::class)->confirmation($this->appointmentFor($theirs));

        $this->actingAs($mine)
            ->get('/messages')
            ->assertOk()
            ->assertSee($mine->notifications()->sole()->id)
            ->assertDontSee($theirs->notifications()->sole()->id);
    }

    public function test_the_navbar_counts_only_unread_messages(): void
    {
        $client = User::factory()->client()->create();

        $appointment = $this->appointmentFor($client);
        app(ClientNotificationService::class)->confirmation($appointment);

        $notification = $client->fresh()->notifications()->sole();

        // "1 unread" in the bell's accessible name rather than the digit in
        // the badge: the digit also appears elsewhere in the page, so this
        // asserts the count is actually being announced.
        $this->actingAs($client)->get('/')->assertSee(', 1 unread', escape: false);

        $notification->markAsRead();

        $this->actingAs($client)->get('/')->assertDontSee(', 1 unread', escape: false);
    }

    public function test_an_empty_inbox_explains_what_will_appear(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/messages')
            ->assertOk()
            ->assertSee('No messages yet', escape: false);

        // Staff and clients are told different things, because they are
        // waiting for different things.
        $this->actingAs(User::factory()->staff()->create(['service_window_id' => $this->window->id]))
            ->get('/messages')
            ->assertOk()
            ->assertSee('Queue alerts will appear here', escape: false);
    }

    /* ------------------------------------------------------------------ */
    /* Reading */
    /* ------------------------------------------------------------------ */

    public function test_opening_a_client_message_marks_it_read_and_follows_the_appointment(): void
    {
        $client = User::factory()->client()->create();
        $appointment = $this->appointmentFor($client);

        app(ClientNotificationService::class)->confirmation($appointment);
        $notification = $client->fresh()->notifications()->sole();

        $this->actingAs($client)
            ->get("/messages/{$notification->id}")
            ->assertRedirect(route('client.appointments.show', $appointment));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_opening_someone_elses_message_is_a_404(): void
    {
        $mine = User::factory()->client()->create();
        $theirs = User::factory()->client()->create();

        app(ClientNotificationService::class)->confirmation($this->appointmentFor($theirs));
        $theirsNotification = $theirs->fresh()->notifications()->sole();

        $this->actingAs($mine)
            ->get("/messages/{$theirsNotification->id}")
            ->assertNotFound();

        $this->assertNull($theirsNotification->fresh()->read_at);
    }

    public function test_an_appointment_id_in_a_payload_does_not_grant_access(): void
    {
        /*
         * The inbox has to resolve a message to the appointment it describes.
         * Doing that by id alone would let a tampered or stale row open
         * somebody else's record, so the lookup goes through the signed-in
         * user's own appointments.
         */
        $owner = User::factory()->client()->create();
        $stranger = User::factory()->client()->create();

        $appointment = $this->appointmentFor($owner);
        app(ClientNotificationService::class)->confirmation($appointment);

        $notification = $owner->fresh()->notifications()->sole();
        $notification->update(['data' => [
            'type' => 'appointment.confirmed',
            'title' => 'Appointment confirmed',
            'appointment_id' => $appointment->id,
            'summary' => 'Booked.',
        ]]);

        $this->actingAs($stranger)
            ->get("/messages/{$notification->fresh()->id}")
            ->assertNotFound();
    }

    public function test_mark_all_as_read_clears_only_your_own_messages(): void
    {
        $mine = User::factory()->client()->create();
        $theirs = User::factory()->client()->create();

        app(ClientNotificationService::class)->confirmation($this->appointmentFor($mine));
        app(ClientNotificationService::class)->confirmation($this->appointmentFor($theirs));

        $this->actingAs($mine)
            ->post('/messages/read-all')
            ->assertRedirect(route('notifications.index'));

        $this->assertSame(0, $mine->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $theirs->fresh()->unreadNotifications()->count());
    }

    public function test_deleting_someone_elses_message_is_a_404(): void
    {
        $mine = User::factory()->client()->create();
        $theirs = User::factory()->client()->create();

        app(ClientNotificationService::class)->confirmation($this->appointmentFor($theirs));
        $notification = $theirs->fresh()->notifications()->sole();

        $this->actingAs($mine)
            ->delete("/messages/{$notification->id}")
            ->assertNotFound();

        $this->assertSame(1, $theirs->fresh()->notifications()->count());
    }

    public function test_a_client_can_delete_their_own_message(): void
    {
        $client = User::factory()->client()->create();

        app(ClientNotificationService::class)->confirmation($this->appointmentFor($client));
        $notification = $client->fresh()->notifications()->sole();

        $this->actingAs($client)
            ->delete("/messages/{$notification->id}")
            ->assertRedirect();

        $this->assertSame(0, $client->fresh()->notifications()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Role-aligned back-office messages */
    /* ------------------------------------------------------------------ */

    public function test_an_ops_message_renders_for_staff_as_well_as_admins(): void
    {
        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        Notification::send($staff, new OpsNotification(
            title: 'Your window assignment has changed',
            summary: 'You have been moved to Window 2.',
            url: route('staff.dashboard'),
            level: 'warning',
        ));

        $this->actingAs($staff)
            ->get('/messages')
            ->assertOk()
            ->assertSee('Your window assignment has changed', escape: false)
            ->assertSee('You have been moved to Window 2.', escape: false);

        // And following it lands on the console rather than the inbox.
        $notification = $staff->fresh()->notifications()->sole();

        $this->actingAs($staff)
            ->get("/messages/{$notification->id}")
            ->assertRedirect(route('staff.dashboard'));
    }

    public function test_an_ops_url_is_not_followed_off_site(): void
    {
        $admin = User::factory()->admin()->create();

        Notification::send($admin, new OpsNotification(
            title: 'Impostor',
            summary: 'This one points somewhere else entirely.',
            url: 'https://example.invalid/steal',
        ));

        $notification = $admin->fresh()->notifications()->sole();

        // The payload is generated server-side, but validating the shape means
        // a malformed or hand-inserted row cannot turn "open this message"
        // into an open redirect.
        $this->actingAs($admin)
            ->get("/messages/{$notification->id}")
            ->assertRedirect(route('notifications.index'));
    }

    public function test_a_client_does_not_receive_back_office_messages(): void
    {
        Notification::fake();

        $client = User::factory()->client()->create();
        $admin = User::factory()->admin()->create();

        app(OpsNotificationService::class)->clientRegistered($client, true);

        Notification::assertNotSentTo($client, OpsNotification::class);
        Notification::assertSentTo($admin, OpsNotification::class);
    }

    public function test_an_administrator_is_told_about_a_priority_claim_but_a_clerk_is_not(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        app(OpsNotificationService::class)->clientRegistered(
            User::factory()->client()->create(),
            true
        );

        Notification::assertSentTo($admin, OpsNotification::class, function (OpsNotification $n) {
            return $n->title === 'New account claiming priority';
        });

        Notification::assertNotSentTo($staff, OpsNotification::class);
    }

    public function test_a_password_reset_tells_the_clerk_without_storing_the_password(): void
    {
        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        app(OpsNotificationService::class)->passwordWasReset($staff);

        $data = $staff->fresh()->notifications()->sole()->data;

        $this->assertSame('Your password was reset', $data['title']);
        $this->assertSame('critical', $data['level']);

        // The message must name the action and what to do about it, and must
        // not carry the temporary password: storing it in clear text in a table
        // an administrator can read would be a way to impersonate staff. The
        // summary is the whole payload we assert, because that is the field a
        // reader sees.
        $this->assertStringContainsString('reset your password', $data['summary']);
        $this->assertStringContainsString('change it', $data['summary']);
        $this->assertStringNotContainsString(
            'Sign in with the temporary password',
            $data['summary'],
        );
    }
}
