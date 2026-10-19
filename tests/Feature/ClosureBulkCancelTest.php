<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A declared closure has to say what happens to the people holding a booking.
 *
 * Decision D8: flag for staff review, then offer to cancel in bulk and notify.
 * Cancelling by hand, one client at a time, is the work that does not get done -
 * the closure gets declared and the affected clients are never told.
 *
 * The important part is that this goes through the ordinary cancellation path.
 * Every cancellation has to release the slot, write a status history, write an
 * audit entry, re-announce the lobby board and notify the client subject to their
 * own channel preferences. A bulk UPDATE would have been four lines and would
 * have skipped all five.
 */
class ClosureBulkCancelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceWindow $window;

    private Service $service;

    private Holiday $holiday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'code' => 'CERT-IND',
            'name' => 'Certificate of Indigency',
        ]);

        $this->holiday = Holiday::create([
            'date' => today()->addDays(5)->toDateString(),
            'name' => 'Foundational Day',
        ]);
    }

    /**
     * One slot per date, reused.
     *
     * time_slots is unique on (service_id, slot_date, start_time), so building a
     * fresh 09:00 slot per booking raised a constraint violation the second time
     * round. Several clients sharing one slot is also the realistic case: the
     * capacity is there precisely so they can.
     */
    private array $slots = [];

    private function slotFor(string $date): TimeSlot
    {
        if (isset($this->slots[$date])) {
            return $this->slots[$date];
        }

        $start = Carbon::parse($date)->setTime(9, 0);

        return $this->slots[$date] = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addHours(4)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addHours(4)->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    private function booking(string $status = 'confirmed', ?string $date = null): Appointment
    {
        $when = Carbon::parse($date ?? $this->holiday->date->toDateString())->setTime(9, 0);
        $slot = $this->slotFor($when->toDateString());

        return Appointment::factory()->create([
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'time_slot_id' => $slot->id,
            'scheduled_at' => $when->toDateTimeString(),
            'queue_date' => $when->toDateString(),
            'status' => $status,
        ]);
    }

    /* ------------------------------------------------------------------ */

    public function test_it_cancels_every_booking_on_the_closure_date(): void
    {
        $a = $this->booking();
        $b = $this->booking();
        $c = $this->booking('pending');

        $this->actingAs($this->admin)
            ->post(route('admin.holidays.cancel-affected', $this->holiday))
            ->assertSessionHas('success');

        foreach ([$a, $b, $c] as $appointment) {
            $this->assertSame(
                AppointmentStatus::Cancelled,
                $appointment->fresh()->status,
                'A booking on the closure date survived the bulk cancel.',
            );
        }
    }

    public function test_a_booking_on_another_date_is_untouched(): void
    {
        $mine = $this->booking();
        $theirs = $this->booking('confirmed', today()->addDays(9)->toDateString());

        $this->actingAs($this->admin)
            ->post(route('admin.holidays.cancel-affected', $this->holiday))
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Cancelled, $mine->fresh()->status);
        $this->assertNotSame(
            AppointmentStatus::Cancelled,
            $theirs->fresh()->status,
            'A booking on a different date was cancelled by mistake.',
        );
    }

    /** A place returned to the pool, or the window ends up over-subscribed. */
    public function test_the_slot_place_is_given_back(): void
    {
        $this->booking();
        $slot = $this->slotFor($this->holiday->date->toDateString());

        $slot->update(['booked_count' => 1]);

        $this->actingAs($this->admin)
            ->post(route('admin.holidays.cancel-affected', $this->holiday))
            ->assertSessionHas('success');

        $this->assertSame(0, $slot->fresh()->booked_count);
    }

    /** Who did it, and why - an audit trail that only shows success is an all-clear. */
    public function test_each_cancellation_is_audited_against_the_administrator(): void
    {
        $appointment = $this->booking();

        $this->actingAs($this->admin)
            ->post(route('admin.holidays.cancel-affected', $this->holiday));

        $entry = AuditLog::where('action', 'appointment.cancelled')
            ->latest()
            ->first();

        $this->assertNotNull($entry, 'The cancellation was not written to the audit trail.');
        $this->assertSame($appointment->id, $entry->subject_id);
        $this->assertStringContainsString(
            'the office is closed',
            strtolower((string) $entry->payload['reason']),
            'The reason recorded does not say why, so the client is told nothing useful.',
        );
    }

    /** The client has to be able to see it was the office, not a system error. */
    public function test_the_reason_names_the_closure(): void
    {
        $appointment = $this->booking();

        $this->actingAs($this->admin)
            ->post(route('admin.holidays.cancel-affected', $this->holiday));

        $reason = (string) $appointment->fresh()->cancel_reason;

        $this->assertStringContainsString('office is closed', strtolower($reason));
        $this->assertStringContainsString($this->holiday->name, $reason);
    }

    public function test_the_status_history_records_the_change(): void
    {
        $appointment = $this->booking();

        $this->actingAs($this->admin)
            ->post(route('admin.holidays.cancel-affected', $this->holiday));

        $this->assertGreaterThan(0, $appointment->fresh()->statusHistories()->count());
    }

    /* ------------------------------------------------------------------ */

    public function test_nothing_to_cancel_says_so_rather_than_failing(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.holidays.cancel-affected', $this->holiday))
            ->assertSessionHas('success');
    }

    /** Only a client, not staff, cannot reach the admin section at all. */
    public function test_a_client_cannot_bulk_cancel(): void
    {
        $appointment = $this->booking();

        $this->actingAs(User::factory()->client()->create())
            ->post(route('admin.holidays.cancel-affected', $this->holiday))
            ->assertForbidden();

        $this->assertNotSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    /** A holiday with no bookings must not produce an error. */
    public function test_a_closure_with_no_bookings_is_harmless(): void
    {
        $empty = Holiday::create([
            'date' => today()->addDays(20)->toDateString(),
            'name' => 'Another holiday',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.holidays.cancel-affected', $empty))
            ->assertSessionHas('success');
    }

    /* ------------------------------------------------------------------ */
    /* The screen offers it, and says what it does */
    /* ------------------------------------------------------------------ */

    public function test_the_screen_offers_the_bulk_action_naming_the_count(): void
    {
        $this->booking();
        $this->booking();

        $html = $this->actingAs($this->admin)->get('/admin/holidays')->assertOk()->getContent();

        $this->assertStringContainsString('Cancel all 2 and notify clients', $html);
        $this->assertStringContainsString('cancel-affected', $html);
    }

    public function test_the_screen_does_not_offer_it_when_nothing_is_affected(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/holidays')->assertOk()->getContent();

        $this->assertStringNotContainsString('cancel-affected', $html);
    }
}
