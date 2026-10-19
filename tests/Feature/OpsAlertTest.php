<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use App\Notifications\OpsNotification;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The alerts added for the notification gaps: a closure that strands clients,
 * and a window that has nothing left for today.
 *
 * Both go to administrators rather than staff. Staff were kept to messages
 * about themselves; these are decisions about the whole office.
 */
class OpsAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private ServiceWindow $window;

    private Service $service;

    /**
     * The next Monday, not today.
     *
     * The office does not open on Sundays, and these tests run on whatever day
     * it happens to be - a slot dated today is then unbookable and every booking
     * throws SlotUnavailableException, which looks like an alert bug rather than
     * a closed-day one.
     */
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->date = today()->next(Carbon::MONDAY)->toDateString();

        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->client()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'name' => 'Certificate of Indigency',
        ]);
    }

    private function slot(string $start, int $capacity = 1): TimeSlot
    {
        return TimeSlot::firstOrCreate(
            [
                'service_id' => $this->service->id,
                'slot_date' => $this->date,
                'start_at' => $this->date.' '.$start.':00',
            ],
            [
                'service_window_id' => $this->window->id,
                'start_time' => $start.':00',
                'end_time' => $start.':30',
                'end_at' => $this->date.' '.$start.':30',
                'capacity' => $capacity,
                'booked_count' => 0,
                'is_open' => true,
            ],
        );
    }

    /**
     * The gap this closes: declaring a closure hides slots but cancels nobody.
     * The dashboard banner and the holidays screen both reported the count to
     * whoever happened to be looking at them, and told nobody else.
     */
    public function test_declaring_a_closure_over_bookings_alerts_the_administrator(): void
    {
        $slot = $this->slot('09', capacity: 5);

        app(BookingService::class)->book($this->client, $this->service, $slot->id);

        Notification::fake();

        $this->actingAs($this->admin)
            ->post('/admin/holidays', [
                'date' => $this->date,
                'name' => 'Unannounced Closure',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->admin, OpsNotification::class, function (OpsNotification $n) {
            return $n->level === 'warning'
                && str_contains($n->summary, '1 client');
        });
    }

    /**
     * Not sent when nobody is affected. A notification that fires on every
     * closure trains people to dismiss the ones that matter.
     */
    public function test_a_closure_with_nobody_booked_alerts_nobody(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post('/admin/holidays', [
                'date' => $this->date,
                'name' => 'Quiet Closure',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertNotSentTo($this->admin, OpsNotification::class);
    }

    /**
     * Scoped to today on purpose. "A window is full" read literally would fire
     * every time any slot filled, which is every few minutes on a working day.
     */
    public function test_filling_the_last_slot_of_the_day_alerts_once(): void
    {
        $slot = $this->slot('09', capacity: 1);

        Notification::fake();

        app(BookingService::class)->book($this->client, $this->service, $slot->id);

        Notification::assertSentTo($this->admin, OpsNotification::class, function (OpsNotification $n) {
            return str_contains($n->title, 'full');
        });
    }

    /**
     * A window that is not yet full says nothing. Booking into a window with
     * slots still open is normal operation, not an alert.
     */
    public function test_a_window_with_capacity_left_is_not_an_alert(): void
    {
        $this->slot('09', capacity: 1);
        $this->slot('10', capacity: 1);

        Notification::fake();

        app(BookingService::class)->book($this->client, $this->service, $this->slot('09')->id);

        // Scoped to this alert rather than to OpsNotification at all: a booking
        // can legitimately produce other ops traffic (a failed broadcast, for
        // one), and what is under test is the capacity alert's silence.
        Notification::assertNotSentTo($this->admin, OpsNotification::class, function (OpsNotification $n) {
            return str_contains($n->title, 'full');
        });
    }

    /**
     * The alert is deduplicated per window per day, and re-armed when capacity
     * frees up - a window that fills, cancels, then fills again really is full
     * again, but must not repeat itself for every booking in between.
     */
    public function test_the_capacity_alert_does_not_repeat_for_the_same_full_day(): void
    {
        $slot = $this->slot('09', capacity: 1);

        Notification::fake();

        app(BookingService::class)->book($this->client, $this->service, $slot->id);

        // Freeing the slot must re-arm the alert.
        $appointment = Appointment::forWindow($this->window->id)->firstOrFail();
        app(BookingService::class)->cancel($appointment, 'testing');

        $second = User::factory()->client()->create();
        app(BookingService::class)->book($second, $this->service, $slot->id);

        $sent = Notification::sent($this->admin, OpsNotification::class);

        $this->assertSame(
            2,
            $sent->filter(fn ($n) => str_contains($n->title, 'full'))->count(),
            'titles: '.implode(' | ', $sent->map(fn ($n) => $n->title)->all()),
        );
    }

    public function test_staff_are_not_sent_operational_alerts(): void
    {
        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        Notification::fake();

        $this->actingAs($this->admin)
            ->post('/admin/holidays', ['date' => $this->date, 'name' => 'Closure'])
            ->assertSessionHasNoErrors();

        Notification::assertNotSentTo($staff, OpsNotification::class);
    }

    /**
     * Cancellations and no-shows leave nothing on the console's waiting line - a
     * cancelled ticket simply stops being there - so without a panel a freed
     * slot or a missing client is invisible from the counter. A panel rather than
     * a notification, because staff were kept to messages about themselves.
     */
    public function test_the_console_shows_recent_cancellations_and_no_shows(): void
    {
        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);
        $slot = $this->slot('09', capacity: 5);

        $appointment = app(BookingService::class)->book($this->client, $this->service, $slot->id);
        app(BookingService::class)->cancel($appointment, 'client rescheduled');

        // The console opens on today's queue by default; these appointments are
        // dated $this->date, so without the query string the panel is empty and
        // the assertion below would pass for the wrong reason.
        $html = $this->actingAs($staff)
            ->get('/staff?date='.$this->date)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cancelled &amp; no-show', $html);
        $this->assertStringContainsString('Slot is free', $html);
    }

    /**
     * The panel must not list work still to do, or it is noise on every visit.
     */
    public function test_the_panel_stays_empty_while_nothing_is_lost(): void
    {
        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);
        $slot = $this->slot('09', capacity: 5);

        app(BookingService::class)->book($this->client, $this->service, $slot->id);

        $html = $this->actingAs($staff)
            ->get('/staff?date='.$this->date)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Slot is free', $html);
    }

    /**
     * Another window's cancellations are not this operator's business.
     */
    public function test_the_panel_shows_only_the_operators_own_window(): void
    {
        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        $otherWindow = ServiceWindow::factory()->create(['code' => 'WINDOW_2']);
        $otherService = Service::factory()->create(['service_window_id' => $otherWindow->id]);

        $otherSlot = TimeSlot::factory()->create([
            'service_id' => $otherService->id,
            'service_window_id' => $otherWindow->id,
            'slot_date' => $this->date,
        ]);

        $otherAppointment = Appointment::factory()->create([
            'service_id' => $otherService->id,
            'service_window_id' => $otherWindow->id,
            'time_slot_id' => $otherSlot->id,
            'user_id' => $this->client->id,
            'status' => 'cancelled',
            'queue_date' => $this->date,
        ]);

        $this->assertNotNull($otherAppointment);

        $html = $this->actingAs($staff)
            ->get('/staff?date='.$this->date)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Slot is free', $html);
    }

    /**
     * The record of the closure still exists regardless of the alert - the
     * alert is a nudge, not the system of record.
     */
    public function test_the_closure_is_still_recorded(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post('/admin/holidays', ['date' => $this->date, 'name' => 'Quiet Closure'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Holiday::where('name', 'Quiet Closure')->first());
    }
}
