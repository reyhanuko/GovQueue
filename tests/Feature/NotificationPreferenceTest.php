<?php

namespace Tests\Feature;

use App\Enums\NotificationChannelType;
use App\Models\Appointment;
use App\Models\NotificationLog;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ClientNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Per-client notification preferences.
 *
 * Until Sprint 9, ClientNotificationService::fanOut() sent email and SMS on the
 * strength of office-wide config alone. A resident with no phone number, no
 * credit, or no interest in texts had no way to opt out, and the office had no
 * way to explain why people were being texted.
 *
 * Two rules are load-bearing here and easy to break:
 *  - a null preference must read as "opted in", not "opted out", or the migration
 *    silently stops messaging every existing client;
 *  - in-app is never suppressible, because it is the record a client sees at the
 *    office, and a client who turned everything else off still has to be able to
 *    learn their appointment moved.
 */
class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'govqueue.notifications.enabled' => true,
            'govqueue.notifications.email' => true,
            'govqueue.notifications.sms' => true,
            'govqueue.notifications.in_app' => true,
        ]);

        $this->service = Service::factory()->create([
            'service_window_id' => ServiceWindow::factory()->create()->id,
            'code' => 'CERT-IND',
        ]);

        $this->client = User::factory()->client()->create([
            'phone' => '09171234567',
            'email' => 'ana@example.test',
        ]);
    }

    private function book(): Appointment
    {
        $this->slotCounter++;
        $startAt = today()->addDay()->setTime(9, $this->slotCounter * 10);

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->service->service_window_id,
            'slot_date' => $startAt->toDateString(),
            'start_time' => $startAt->format('H:i:s'),
            'end_time' => $startAt->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $startAt->toDateString().' '.$startAt->format('H:i:s'),
            'end_at' => $startAt->toDateString().' '.$startAt->copy()->addMinutes(30)->format('H:i:s'),
            'capacity' => 5,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        return app(BookingService::class)->book($this->client, $this->service, $slot->id);
    }

    /** @return array<int, string> channels actually logged for this booking */
    private function channelsFor(Appointment $appointment): array
    {
        return NotificationLog::where('appointment_id', $appointment->id)
            ->pluck('channel')
            ->map(fn ($c) => $c->value ?? (string) $c)
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* Defaults */
    /* ------------------------------------------------------------------ */

    public function test_clients_are_opted_in_by_default(): void
    {
        $client = $this->client->fresh();

        $this->assertTrue((bool) $client->notify_email);
        $this->assertTrue((bool) $client->notify_sms);
        $this->assertTrue($client->wantsEmail());
        $this->assertTrue($client->wantsSms());
    }

    /**
     * The migration must not silence every existing client.
     *
     * The columns are NOT NULL with a default of true, so the migration does give
     * every existing row an opt-in. The model's `?? true` fallback is the second
     * line of defence for any deployment where a row could still be null - a
     * partially applied migration, or a hand-inserted legacy row - so it is
     * tested directly rather than through the database, which will not allow it.
     */
    public function test_a_null_preference_reads_as_opted_in(): void
    {
        $legacy = new User([
            'notify_email' => null,
            'notify_sms' => null,
            'phone' => '09171234567',
        ]);

        $this->assertTrue(
            $legacy->wantsEmail(),
            'A null preference must not read as opted out, or the change would silence everyone.'
        );
        $this->assertTrue($legacy->wantsSms());
        $this->assertFalse($legacy->hasNoOutboundChannel());
    }

    public function test_an_explicit_opt_out_is_still_respected(): void
    {
        $optedOut = new User([
            'notify_email' => false,
            'notify_sms' => false,
            'phone' => '09171234567',
        ]);

        $this->assertFalse($optedOut->wantsEmail());
        $this->assertFalse($optedOut->wantsSms());
        $this->assertTrue($optedOut->hasNoOutboundChannel());
    }

    public function test_the_default_booking_notifies_on_every_channel(): void
    {
        $appointment = $this->book();

        $channels = $this->channelsFor($appointment);

        $this->assertContains(NotificationChannelType::Email->value, $channels);
        $this->assertContains(NotificationChannelType::Sms->value, $channels);
        $this->assertContains(NotificationChannelType::InApp->value, $channels);
    }

    /* ------------------------------------------------------------------ */
    /* Opting out */
    /* ------------------------------------------------------------------ */

    public function test_a_client_can_opt_out_of_email(): void
    {
        Mail::fake();

        $this->client->update(['notify_email' => false]);

        $appointment = $this->book();

        Mail::assertNothingSent();

        $channels = $this->channelsFor($appointment);

        $this->assertNotContains(NotificationChannelType::Email->value, $channels);
        // The other channels still work.
        $this->assertContains(NotificationChannelType::Sms->value, $channels);
        $this->assertContains(NotificationChannelType::InApp->value, $channels);
    }

    public function test_a_client_can_opt_out_of_sms(): void
    {
        $this->client->update(['notify_sms' => false]);

        $appointment = $this->book();

        $channels = $this->channelsFor($appointment);

        $this->assertNotContains(NotificationChannelType::Sms->value, $channels);
        $this->assertContains(NotificationChannelType::Email->value, $channels);
    }

    public function test_a_client_can_opt_out_of_both(): void
    {
        Mail::fake();

        $this->client->update(['notify_email' => false, 'notify_sms' => false]);

        $appointment = $this->book();

        Mail::assertNothingSent();

        $channels = $this->channelsFor($appointment);

        $this->assertNotContains(NotificationChannelType::Email->value, $channels);
        $this->assertNotContains(NotificationChannelType::Sms->value, $channels);

        // In-app survives, so the client is not left completely in the dark.
        $this->assertContains(NotificationChannelType::InApp->value, $channels);
        $this->assertTrue($this->client->hasNoOutboundChannel());
    }

    /* ------------------------------------------------------------------ */
    /* No phone number */
    /* ------------------------------------------------------------------ */

    /**
     * A client with no number on file still produces a delivery row explaining
     * why. That behaviour was chosen in Sprint 6 and is how staff discover they
     * cannot text someone, so the new preference must not quietly remove it -
     * this preference is about consent, not reachability.
     */
    public function test_a_client_without_a_phone_still_gets_an_explanatory_row(): void
    {
        $this->client->update(['phone' => null, 'notify_sms' => true]);

        $this->assertTrue($this->client->fresh()->wantsSms());

        $appointment = $this->book();

        $row = NotificationLog::where('appointment_id', $appointment->id)
            ->where('channel', NotificationChannelType::Sms->value)
            ->first();

        $this->assertNotNull($row, 'Staff still need to see that a client has no number.');
        $this->assertSame('failed', $row->status->value);
        $this->assertStringContainsString('no mobile number', (string) $row->error_message);
    }

    /**
     * Opting out is different: nothing is recorded at all, because no attempt was
     * made. A "failed" row for a client who said no would be misleading noise.
     */
    public function test_opting_out_of_sms_records_nothing_at_all(): void
    {
        $this->client->update(['phone' => null, 'notify_sms' => false]);

        $appointment = $this->book();

        $this->assertSame(
            0,
            NotificationLog::where('appointment_id', $appointment->id)
                ->where('channel', NotificationChannelType::Sms->value)
                ->count(),
            'No attempt was made, so there is nothing to report.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Office-wide config still wins */
    /* ------------------------------------------------------------------ */

    public function test_an_office_wide_switch_beats_a_client_opt_in(): void
    {
        config(['govqueue.notifications.sms' => false]);

        $this->assertTrue($this->client->wantsSms());

        $appointment = $this->book();

        $this->assertNotContains(
            NotificationChannelType::Sms->value,
            $this->channelsFor($appointment)
        );
    }

    public function test_the_preference_survives_a_profile_save(): void
    {
        $this->client->update(['notify_sms' => false]);

        $this->actingAs($this->client)
            ->put('/profile', [
                'name' => $this->client->name,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
                'barangay' => $this->client->barangay,
                'birthdate' => '1990-01-01',
            ])
            ->assertRedirect();

        // Saving the profile must not quietly re-enable a channel the client
        // turned off: the profile form does not carry these fields at all.
        $this->assertFalse((bool) $this->client->fresh()->notify_sms);
    }

    /* ------------------------------------------------------------------ */
    /* The screen */
    /* ------------------------------------------------------------------ */

    public function test_the_profile_page_offers_the_preferences(): void
    {
        $this->actingAs($this->client)
            ->get('/profile')
            ->assertOk()
            ->assertSee('Appointment notifications')
            ->assertSee(route('client.profile.notifications'), escape: false);
    }

    public function test_the_profile_page_reflects_the_current_choice(): void
    {
        $this->client->update(['notify_sms' => false]);

        $content = $this->actingAs($this->client)->get('/profile')->assertOk()->getContent();

        // Email ticked, SMS not.
        $this->assertMatchesRegularExpression(
            '/name="notify_email"[^>]*checked|name="notify_email"[^>]*value="1"\s+checked/',
            $content
        );

        $this->assertDoesNotMatchRegularExpression(
            '/name="notify_sms"[^>]*checked/',
            $content
        );
    }

    public function test_turning_everything_off_is_explained_rather_than_left_silent(): void
    {
        $this->actingAs($this->client)
            ->put('/profile/notifications', [])
            ->assertRedirect(route('client.profile.edit'))
            ->assertSessionHas('success');
    }

    public function test_a_client_without_a_phone_number_is_told_to_add_one(): void
    {
        $this->client->update(['phone' => null]);

        $this->actingAs($this->client)
            ->get('/profile')
            ->assertOk()
            ->assertSee('Add a mobile number');
    }

    public function test_the_preference_controls_are_labelled(): void
    {
        $content = $this->actingAs($this->client)->get('/profile')->assertOk()->getContent();

        // Both checkboxes sit inside their own <label>, so the text is the
        // accessible name.
        $this->assertMatchesRegularExpression('/<label[^>]*>\s*<input[^>]*name="notify_email"/', $content);
        $this->assertMatchesRegularExpression('/<label[^>]*>\s*<input[^>]*name="notify_sms"/', $content);
    }

    /* ------------------------------------------------------------------ */
    /* Reminders follow the same rules */
    /* ------------------------------------------------------------------ */

    public function test_reminders_respect_the_preference_too(): void
    {
        $this->client->update(['notify_sms' => false]);

        $appointment = $this->book();

        app(ClientNotificationService::class)->reminder($appointment, 24);

        $sms = NotificationLog::where('appointment_id', $appointment->id)
            ->where('channel', NotificationChannelType::Sms->value)
            ->where('type', 'appointment.reminder')
            ->count();

        $this->assertSame(0, $sms, 'A client who opted out of SMS must not get reminder texts.');
    }
}
