<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Getting from the public catalogue to a booking.
 *
 * Reported bug: a signed-in client on a service page was shown a "Sign in to
 * book" button that pointed at /login, so booking looked impossible even though
 * they were already authenticated. These tests pin the whole path - the call to
 * action, the link from a previewed slot, and honouring the day a client picked.
 */
class BookingEntryPointTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = ServiceWindow::factory()->create([
            'name' => 'Window 1 - Certificates',
            'code' => 'WINDOW_1',
        ]);

        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'name' => 'Certificate of Indigency',
            'code' => 'CERT-IND',
            'is_active' => true,
        ]);
    }

    private function slot(string $date, string $start = '09:00:00'): TimeSlot
    {
        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $date,
            'start_time' => $start,
            'end_time' => date('H:i:s', strtotime($start) + 1800),
            'start_at' => $date.' '.$start,
            'end_at' => date('Y-m-d H:i:s', strtotime($date.' '.$start) + 1800),
            'capacity' => 4,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Guests */
    /* ------------------------------------------------------------------ */

    public function test_a_guest_is_prompted_to_sign_in_or_register(): void
    {
        $this->slot(today()->addDay()->toDateString());

        $this->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee('Sign in to book')
            ->assertSee(route('register'), escape: false);
    }

    /* ------------------------------------------------------------------ */
    /* The reported bug */
    /* ------------------------------------------------------------------ */

    public function test_a_signed_in_client_is_not_asked_to_sign_in_again(): void
    {
        $this->slot(today()->addDay()->toDateString());

        $response = $this->actingAs(User::factory()->client()->create())
            ->get('/services/CERT-IND')
            ->assertOk();

        // The whole point of the fix: no sign-in prompt for someone who is
        // already signed in.
        $response->assertDontSee('Sign in to book');
        $response->assertSee('Book this service');
    }

    public function test_a_signed_in_client_gets_a_working_link_to_the_booking_screen(): void
    {
        $this->slot(today()->addDay()->toDateString());

        $this->actingAs(User::factory()->client()->create())
            ->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee(route('client.booking.create', $this->service), escape: false);
    }

    public function test_staff_are_sent_to_their_own_dashboard_rather_than_a_booking_screen(): void
    {
        $this->slot(today()->addDay()->toDateString());

        // Staff have no client booking flow. Showing them "Book this service"
        // would be a dead end.
        $this->actingAs(User::factory()->staff()->create(['service_window_id' => $this->window->id]))
            ->get('/services/CERT-IND')
            ->assertOk()
            ->assertDontSee('Sign in to book')
            ->assertDontSee('Book this service');
    }

    /* ------------------------------------------------------------------ */
    /* Slots are actionable, not decorative */
    /* ------------------------------------------------------------------ */

    public function test_a_client_can_jump_straight_to_the_booking_screen_from_a_slot(): void
    {
        $date = today()->addDay()->toDateString();

        $this->slot($date);

        $this->actingAs(User::factory()->client()->create())
            ->get('/services/CERT-IND')
            ->assertOk()
            // The day they clicked is carried across to the booking screen.
            ->assertSee(route('client.booking.create', $this->service).'?date='.$date, escape: false);
    }

    public function test_a_guest_sees_slots_as_information_only(): void
    {
        $this->slot(today()->addDay()->toDateString());

        $this->get('/services/CERT-IND')
            ->assertOk()
            ->assertDontSee(route('client.booking.create', $this->service), escape: false);
    }

    /* ------------------------------------------------------------------ */
    /* Honouring ?date= */
    /* ------------------------------------------------------------------ */

    public function test_the_requested_day_is_shown_first(): void
    {
        $first = today()->addDay()->toDateString();
        $third = today()->addDays(3)->toDateString();

        $this->slot($first);
        $this->slot(today()->addDays(2)->toDateString());
        $this->slot($third);

        $response = $this->actingAs(User::factory()->client()->create())
            ->get('/services/CERT-IND/book?date='.$third)
            ->assertOk();

        $response->assertSee('the day you picked');

        // Ordering is the observable behaviour: the requested day must come
        // before the earlier ones.
        $thirdPos = strpos($response->getContent(), 'id="day-'.$third.'"');
        $firstPos = strpos($response->getContent(), 'id="day-'.$first.'"');

        $this->assertNotFalse($thirdPos);
        $this->assertNotFalse($firstPos);
        $this->assertLessThan($firstPos, $thirdPos, 'The requested day should be listed first.');
    }

    public function test_a_bogus_date_is_ignored_rather_than_trusted(): void
    {
        $this->slot(today()->addDay()->toDateString());

        $this->actingAs(User::factory()->client()->create())
            ->get('/services/CERT-IND/book?date=1999-01-01')
            ->assertOk()
            ->assertDontSee('the day you picked');
    }

    public function test_a_non_string_date_does_not_error(): void
    {
        $this->slot(today()->addDay()->toDateString());

        // ?date[]=x makes the query value an array. It must not blow up.
        $this->actingAs(User::factory()->client()->create())
            ->get('/services/CERT-IND/book?date[]=x')
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* The reported JSON blob */
    /* ------------------------------------------------------------------ */

    public function test_no_slot_model_is_dumped_into_the_page(): void
    {
        $this->slot(today()->addDay()->toDateString());

        $client = User::factory()->client()->create();

        foreach (['/services/CERT-IND', '/services/CERT-IND/book', '/services'] as $path) {
            $content = $this->actingAs($client)->get($path)->assertOk()->getContent();

            // A serialised TimeSlot starts with its id and service_id. Seeing
            // that pattern in the markup means a model leaked into the view.
            $this->assertDoesNotMatchRegularExpression(
                '/\{"id":\d+,"service_id":\d+/',
                $content,
                "A TimeSlot was serialised into {$path}."
            );
        }
    }
}
