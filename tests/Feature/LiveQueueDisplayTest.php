<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Livewire\Client\QueueStatus;
use App\Livewire\Display\PublicQueueDisplay;
use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The public digital display and the client's live status board.
 */
class LiveQueueDisplayTest extends TestCase
{
    use RefreshDatabase;

    private ServiceWindow $window;

    private Service $service;

    private int $slotCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create([
            'code' => 'WINDOW_1',
            'name' => 'Window 1 - Certificates',
            // Pinned: the factory randomises this, which would make the wait
            // estimate assertions non-deterministic.
            'avg_service_minutes' => 10,
        ]);
        // Duration pinned to the window's average for the same reason: with no
        // completed history the wait estimate falls back to the window's
        // services, and a randomised duration would make these assertions
        // non-deterministic.
        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'duration_minutes' => 10,
        ]);
    }

    private function slot(): TimeSlot
    {
        $this->slotCounter++;
        $start = today()->setTime(9, 0)->addMinutes(($this->slotCounter - 1) * 30);

        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => today()->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 20,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    private function waiting(string $queueNumber, array $overrides = []): Appointment
    {
        $slot = $this->slot();

        $appointment = Appointment::create(array_merge([
            'reference_number' => 'GQ-D-'.uniqid(),
            'user_id' => User::factory()->client()->create()->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'status' => AppointmentStatus::CheckedIn,
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_number' => $queueNumber,
            'queue_date' => today()->toDateString(),
            'confirmed_at' => now(),
            'checked_in_at' => now()->subMinutes(random_int(5, 30)),
        ], $overrides));

        $slot->increment('booked_count');

        return $appointment;
    }

    /* ------------------------------------------------------------------ */
    /* The public display */
    /* ------------------------------------------------------------------ */

    public function test_the_display_is_public(): void
    {
        $this->get('/queue-display')->assertOk();
    }

    public function test_the_display_shows_the_now_serving_number(): void
    {
        $serving = $this->waiting('A-001', [
            'status' => AppointmentStatus::Serving,
            'serving_started_at' => now(),
        ]);

        $this->get('/queue-display')
            ->assertOk()
            ->assertSee('Now serving')
            ->assertSee($serving->queue_number)
            // Names the window, which the old single-window layout did not have
            // to: with every window on the board at once, "the window" would
            // have been ambiguous.
            ->assertSee('Please proceed to '.$this->window->name);
    }

    /**
     * A long queue number is drawn smaller, so it fits the column it is in.
     *
     * A queue number is PREFIX-NNN, and the prefix is up to four characters, so
     * a number is five to eight characters long. Drawn at one size, the long
     * ones overflow - and the all-windows board puts three windows side by
     * side, so a number that spills out of its column is a number half the
     * lobby cannot read.
     *
     * Asserted on the rendered board rather than on the method that decides the
     * size, because the board is the thing a person in the lobby looks at.
     */
    public function test_a_long_queue_number_is_drawn_smaller(): void
    {
        $serving = $this->waiting('A-001', [
            'status' => AppointmentStatus::Serving,
            'serving_started_at' => now(),
        ]);

        $body = $this->get('/queue-display')->assertOk()->getContent();

        $short = $this->classListFor($body, 'A-001');

        $this->assertStringContainsString(
            'text-6xl',
            $short,
            'A five-character queue number is the common case and is drawn at full size. Got: '.$short,
        );

        // The same number on the single-window board, which has the whole width
        // to itself and so can afford a larger size.
        $body = $this->get('/queue-display?w='.$this->window->code)->assertOk()->getContent();

        $short = $this->classListFor($body, 'A-001');

        $this->assertStringContainsString(
            'text-7xl',
            $short,
            'The single-window board has the width to draw the number larger. Got: '.$short,
        );

        // And now a long one: eight characters, which is the longest a queue
        // number can be.
        $serving->update(['queue_number' => 'CLEA-001']);

        $body = $this->get('/queue-display')->assertOk()->getContent();

        $long = $this->classListFor($body, 'CLEA-001');

        $this->assertStringContainsString(
            'text-4xl',
            $long,
            'An eight-character queue number has to be drawn smaller or it overflows its column. Got: '.$long,
        );

        $body = $this->get('/queue-display?w='.$this->window->code)->assertOk()->getContent();

        $long = $this->classListFor($body, 'CLEA-001');

        $this->assertStringContainsString(
            'text-5xl',
            $long,
            'The single-window board scales the long number down too. Got: '.$long,
        );
    }

    /**
     * The classes of the element that holds a given queue number.
     *
     * The board draws a number in more than one place - as the number being
     * served, and in the list of those waiting - so a test has to say which
     * one it is looking at rather than matching the first occurrence of a
     * number anywhere in the page.
     */
    private function classListFor(string $html, string $number): string
    {
        $pattern = '/<(\w+)([^>]*)>\s*'.preg_quote($number, '/').'\s*<\/\1>/';

        if (! preg_match($pattern, $html, $matches)) {
            return '';
        }

        if (! preg_match('/class="([^"]*)"/', $matches[2], $class)) {
            return '';
        }

        return $class[1];
    }

    public function test_the_display_says_so_when_nobody_is_being_served(): void
    {
        $this->get('/queue-display')
            ->assertOk()
            ->assertSee('No client is being served');
    }

    public function test_the_display_lists_the_queue_in_service_order(): void
    {
        $this->waiting('A-001');
        $this->waiting('A-002');

        $this->get('/queue-display')
            ->assertOk()
            ->assertSee('Next in line')
            ->assertSee('A-001')
            ->assertSee('A-002');
    }

    public function test_the_display_applies_priority_ordering(): void
    {
        // Regular client first by arrival, verified PWD second: the PWD must be
        // listed as "Next".
        $this->waiting('A-001');
        $this->waiting('A-002', [
            'claims_pwd' => true,
            'priority_verified_at' => now(),
            'priority_reference' => 'PWD-1',
        ]);

        $response = $this->get('/queue-display')->assertOk();

        $body = $response->getContent();
        $this->assertLessThan(
            strpos($body, 'A-001'),
            strpos($body, 'A-002'),
            'A verified PWD must be shown ahead of an earlier-arriving regular client.',
        );
    }

    public function test_the_display_never_leaks_client_identity(): void
    {
        // Privacy is the whole reason this screen can be unauthenticated.
        $client = User::factory()->client()->create(['name' => 'Confidential Client Name']);
        $service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'name' => 'Confidential Service Name',
        ]);

        $appointment = $this->waiting('A-001', ['user_id' => $client->id, 'service_id' => $service->id]);

        $body = $this->get('/queue-display')->getContent();

        $this->assertStringNotContainsString('Confidential Client Name', $body);
        $this->assertStringNotContainsString('Confidential Service Name', $body);
        $this->assertStringNotContainsString($appointment->reference_number, $body);
    }

    public function test_the_display_never_leaks_priority_claims(): void
    {
        $this->waiting('A-001', [
            'claims_pwd' => true,
            'priority_verified_at' => now(),
            'priority_reference' => 'SECRET-PWD-REF',
        ]);

        $body = $this->get('/queue-display')->getContent();

        $this->assertStringNotContainsString('SECRET-PWD-REF', $body);
        $this->assertStringNotContainsString('Person with Disability', $body);
    }

    public function test_the_display_offers_a_way_back(): void
    {
        /*
         * The board is opened full-screen on a lobby TV or a tablet, with no
         * browser chrome. A kiosk that can display one page and cannot be
         * navigated is a screen nobody can get out of without a keyboard.
         */
        $this->get('/queue-display')
            ->assertOk()
            ->assertSee(route('home'), escape: false)
            ->assertSee('Back to GovQueue');
    }

    public function test_a_malformed_date_does_not_break_the_display(): void
    {
        /*
         * The lobby display has no auth middleware and no throttle - it is meant
         * to face the waiting area - and its date came straight from the query
         * string into Carbon::parse(), which throws on unparseable input. So
         * ?date=<anything> was an anonymous, repeatable 500 on the screen the
         * office puts on the wall.
         *
         * It also re-ran on every 10s poll, so one bad URL kept generating
         * exceptions rather than one.
         */
        foreach (['not-a-date', 'x', '../../etc/passwd', '0000-00-00', 'tomorrow-ish'] as $bad) {
            $this->get('/queue-display?date='.urlencode($bad))
                ->assertOk();
        }
    }

    public function test_a_malformed_date_shows_todays_board_rather_than_an_empty_one(): void
    {
        // Falling back to today is the useful answer. Showing an empty board
        // would read to staff as "nobody is waiting", which is the one thing on
        // this screen that must never be a lie.
        $this->waiting('A-001');

        $this->get('/queue-display?date='.urlencode('not-a-date'))
            ->assertOk()
            ->assertSee(today()->format('l, M d Y'), escape: false)
            ->assertSee('A-001');
    }

    public function test_the_date_component_survives_a_malformed_date(): void
    {
        // The public Livewire action, reachable on every poll.
        Livewire::test(PublicQueueDisplay::class, ['queueDate' => 'not-a-date'])
            ->assertOk()
            ->call('setDate', 'also-not-a-date')
            ->assertOk()
            ->assertSet('queueDate', today()->toDateString());
    }

    public function test_the_display_is_marked_noindex(): void
    {
        $this->get('/queue-display')->assertSee('noindex', false);
    }

    public function test_the_display_reports_a_closed_office(): void
    {
        Holiday::create(['date' => today()->toDateString(), 'name' => 'Maintenance']);

        $this->get('/queue-display')
            ->assertOk()
            ->assertSee('The office is closed today');
    }

    /**
     * The Echo listener finds its channels from data attributes on the root.
     *
     * With the all-windows board as the default there is no single code any
     * more, so the codes are carried as a list. Asserting only that *some*
     * attribute mentions the code would pass even if the JavaScript read the
     * wrong one, which is the failure this test exists to prevent.
     */
    public function test_the_display_carries_the_channel_code_for_websocket_binding(): void
    {
        $content = $this->get('/queue-display')->assertOk()->getContent();

        $this->assertStringContainsString(
            'data-window-codes="'.$this->window->code.'"',
            $content,
            'The Echo listener binds to this attribute, and with every window shown there is no single code.',
        );
    }

    /** Scoped to one window, the board still carries the single code. */
    public function test_a_scoped_display_carries_its_own_channel_code(): void
    {
        $content = $this->get('/queue-display?w='.$this->window->code)->assertOk()->getContent();

        $this->assertStringContainsString('data-window-code="'.$this->window->code.'"', $content);
        $this->assertStringContainsString(
            'data-window-codes="'.$this->window->code.'"',
            $content,
            'The listener reads one or the other; both must be right for the board to update live.',
        );
    }

    public function test_the_display_can_be_scoped_to_a_window(): void
    {
        ServiceWindow::factory()->create(['code' => 'WINDOW_2', 'name' => 'Window 2 - Permits']);

        $this->get('/queue-display?w=WINDOW_2')
            ->assertOk()
            ->assertSee('Window 2 - Permits')
            ->assertSee('data-window-code="WINDOW_2"', false);
    }

    public function test_the_display_explains_the_statutory_basis(): void
    {
        $this->get('/queue-display')
            ->assertOk()
            ->assertSee('RA 9994')
            ->assertSee('RA 10754');
    }

    public function test_an_inactive_window_shows_an_unavailable_banner(): void
    {
        $inactive = ServiceWindow::factory()->create(['is_active' => false]);

        Livewire::test(PublicQueueDisplay::class, ['windowCode' => $inactive->code])
            ->assertSee('This service window is not available');
    }

    public function test_a_broadcast_refreshes_the_display(): void
    {
        // The Echo listener in govqueue.js calls this method directly.
        Livewire::test(PublicQueueDisplay::class, ['windowCode' => $this->window->code])
            ->assertSet('refreshToken', 0)
            ->call('onQueueUpdated')
            ->assertSet('refreshToken', 1);
    }

    public function test_the_display_stamps_its_last_update_time(): void
    {
        $component = Livewire::test(PublicQueueDisplay::class, ['windowCode' => $this->window->code]);

        $this->assertNotNull($component->get('lastUpdatedAt'));
    }

    /* ------------------------------------------------------------------ */
    /* The client's live status board */
    /* ------------------------------------------------------------------ */

    public function test_the_client_board_shows_their_position(): void
    {
        // Deterministic arrival times: the factory uses random offsets, which
        // would make the order of the two tickets non-deterministic.
        $this->waiting('A-001', ['checked_in_at' => now()->subMinutes(20)]);
        $mine = $this->waiting('A-002', ['checked_in_at' => now()->subMinutes(10)]);

        Livewire::actingAs($mine->user)
            ->test(QueueStatus::class, ['appointment' => $mine])
            ->assertSet('position', 2)
            ->assertSee('Your place in the queue')
            ->assertSee('2 of 2');
    }

    public function test_the_client_board_estimates_the_wait(): void
    {
        $this->waiting('A-001', ['checked_in_at' => now()->subMinutes(20)]);
        $mine = $this->waiting('A-002', ['checked_in_at' => now()->subMinutes(10)]);

        Livewire::actingAs($mine->user)
            ->test(QueueStatus::class, ['appointment' => $mine])
            // Window average is 10 minutes; one client ahead.
            ->assertSet('estimatedWaitMinutes', 10)
            ->assertSee('About 10 minutes wait');
    }

    public function test_the_client_board_says_you_are_next(): void
    {
        $mine = $this->waiting('A-001');

        Livewire::actingAs($mine->user)
            ->test(QueueStatus::class, ['appointment' => $mine])
            ->assertSet('position', 1)
            ->assertSee('You are next');
    }

    public function test_the_client_board_prompts_the_client_when_called(): void
    {
        $mine = $this->waiting('A-001', [
            'status' => AppointmentStatus::Serving,
            'serving_started_at' => now(),
        ]);

        Livewire::actingAs($mine->user)
            ->test(QueueStatus::class, ['appointment' => $mine])
            ->assertSet('status', 'serving')
            ->assertSee('Your number has been called');
    }

    public function test_a_client_cannot_view_another_clients_board(): void
    {
        $mine = $this->waiting('A-001');

        // Livewire renders the abort(403) as the site's 403 page rather than
        // throwing, so assert on the rendered output.
        Livewire::actingAs(User::factory()->client()->create())
            ->test(QueueStatus::class, ['appointment' => $mine])
            ->assertSee('Access denied');
    }

    public function test_the_client_board_explains_an_unverified_claim(): void
    {
        $mine = $this->waiting('A-001', ['claims_pwd' => true, 'priority_reference' => 'PWD-1']);

        Livewire::actingAs($mine->user)
            ->test(QueueStatus::class, ['appointment' => $mine])
            ->assertSee('Priority claimed')
            ->assertSee('once staff verify your claim');
    }

    public function test_the_client_board_confirms_a_verified_claim(): void
    {
        $mine = $this->waiting('A-001', [
            'claims_senior_citizen' => true,
            'priority_verified_at' => now(),
        ]);

        Livewire::actingAs($mine->user)
            ->test(QueueStatus::class, ['appointment' => $mine])
            ->assertSee('Priority verified');
    }

    public function test_the_client_board_ignores_a_push_for_a_different_appointment(): void
    {
        $mine = $this->waiting('A-001');
        $other = $this->waiting('A-002');

        Livewire::actingAs($mine->user)
            ->test(QueueStatus::class, ['appointment' => $mine])
            ->call('onStatusChanged', [
                'id' => $mine->id,
                'status' => 'serving',
                'queue_number' => 'A-001',
                'position' => 1,
            ])
            ->assertSet('status', 'serving')
            // A payload for someone else's ticket must not touch this board.
            ->call('onStatusChanged', [
                'id' => $other->id,
                'status' => 'cancelled',
                'queue_number' => 'A-999',
                'position' => 42,
            ])
            ->assertSet('status', 'serving')
            ->assertSet('queueNumber', 'A-001');
    }

    public function test_the_client_board_clears_position_once_closed(): void
    {
        $mine = $this->waiting('A-001');
        $mine->update(['status' => AppointmentStatus::Cancelled]);

        Livewire::actingAs($mine->user)
            ->test(QueueStatus::class, ['appointment' => $mine])
            ->assertSet('position', null)
            ->assertSet('estimatedWaitMinutes', null);
    }

    public function test_the_client_board_carries_the_channel_id_for_websocket_binding(): void
    {
        $mine = $this->waiting('A-001');

        $html = Livewire::actingAs($mine->user)
            ->test(QueueStatus::class, ['appointment' => $mine])
            ->html();

        $this->assertStringContainsString('data-appointment-id="'.$mine->id.'"', $html);
    }

    public function test_the_appointment_page_mounts_the_live_board_for_an_active_ticket(): void
    {
        $mine = $this->waiting('A-001');
        $mine->user->forceFill(['name' => 'Board Holder'])->save();

        $this->actingAs($mine->user)
            ->get(route('client.appointments.show', $mine))
            ->assertOk()
            ->assertSee('Your queue number')
            ->assertSee('data-appointment-id="'.$mine->id.'"', false);
    }

    public function test_the_appointment_page_omits_the_board_for_a_closed_ticket(): void
    {
        $mine = $this->waiting('A-001', ['status' => AppointmentStatus::Cancelled]);
        $mine->user->forceFill(['name' => 'Closed Holder'])->save();

        // Assert on the websocket binding attribute, not on the "Your queue
        // number" heading: the page's own summary card uses that same wording
        // for closed tickets too.
        $this->actingAs($mine->user)
            ->get(route('client.appointments.show', $mine))
            ->assertOk()
            ->assertDontSee('data-appointment-id', false)
            ->assertDontSee('client.queue-status', false);
    }
}
