<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
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
            'fee' => 0,
        ]);
    }

    private function slot(string $date, string $start = '09:00:00', int $capacity = 4, int $booked = 0): TimeSlot
    {
        return TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $date,
            'start_time' => $start,
            'end_time' => date('H:i:s', strtotime($start) + 1800),
            'start_at' => $date.' '.$start,
            'end_at' => date('Y-m-d H:i:s', strtotime($date.' '.$start) + 1800),
            'capacity' => $capacity,
            'booked_count' => $booked,
            'is_open' => true,
        ]);
    }

    public function test_the_catalogue_is_public(): void
    {
        $this->get('/services')->assertOk()->assertSee('Certificate of Indigency');
    }

    public function test_the_catalogue_shows_real_availability(): void
    {
        $this->slot(today()->addDay()->toDateString());

        $this->get('/services')
            ->assertOk()
            ->assertSee('1 open slot available')
            ->assertSee('Next:');
    }

    public function test_a_fully_booked_service_says_so(): void
    {
        $this->slot(today()->addDay()->toDateString(), capacity: 2, booked: 2);

        $this->get('/services')
            ->assertOk()
            ->assertSee('Fully booked for now');
    }

    public function test_it_can_search_by_service_name(): void
    {
        Service::factory()->create(['name' => 'Building Permit', 'code' => 'BLDG']);

        $this->get('/services?q=indigency')
            ->assertOk()
            ->assertSee('Certificate of Indigency')
            ->assertDontSee('Building Permit');
    }

    public function test_it_can_filter_by_window(): void
    {
        $otherWindow = ServiceWindow::factory()->create(['name' => 'Window 2 - Permits', 'code' => 'WINDOW_2']);
        Service::factory()->create([
            'service_window_id' => $otherWindow->id,
            'name' => 'Business Permit',
            'code' => 'BUS-PERMIT',
        ]);

        $this->get('/services?window='.$otherWindow->id)
            ->assertOk()
            ->assertSee('Business Permit')
            ->assertDontSee('Certificate of Indigency');
    }

    public function test_a_search_with_no_matches_explains_itself(): void
    {
        $this->get('/services?q=zzzznotaservice')
            ->assertOk()
            ->assertSee('No services match your search');
    }

    public function test_inactive_services_are_hidden_from_the_catalogue(): void
    {
        $this->service->update(['is_active' => false]);

        $this->get('/services')->assertOk()->assertDontSee('Certificate of Indigency');
    }

    public function test_the_service_detail_page_lists_requirements(): void
    {
        $this->service->update(['requirements' => "Valid ID\nCommunity Tax Certificate"]);

        $this->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee('Certificate of Indigency')
            ->assertSee('What to bring')
            ->assertSee('Valid ID')
            ->assertSee('Community Tax Certificate');
    }

    public function test_the_detail_page_lists_open_slots_with_remaining_capacity(): void
    {
        $this->slot(today()->addDay()->toDateString(), '09:00:00', capacity: 4, booked: 2);
        $this->slot(today()->addDay()->toDateString(), '09:30:00');

        $this->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee('Available time slots')
            ->assertSee('2/4 left')
            ->assertSee('4/4 left');
    }

    public function test_the_detail_page_explains_when_nothing_is_available(): void
    {
        $this->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee('No open slots in the coming weeks');
    }

    /**
     * The preview used to list every slot in the booking horizon.
     *
     * That pushed "Book this service" far below the fold, which is the same
     * wall that was reported on the booking screen - in a fourth place, and on
     * the page a client lands on first.
     */
    public function test_the_detail_page_shows_one_day_of_slots_not_every_day(): void
    {
        $first = today()->addDay()->toDateString();
        $later = today()->addDays(4)->toDateString();

        $this->slot($first, '09:00:00');
        $this->slot($later, '10:00:00');

        $html = $this->get('/services/CERT-IND')->assertOk()->getContent();

        /*
         * Counting the rendered slots is a direct measure of how many times the
         * preview lists, which is the property being fixed.
         *
         * Counted on `places left"` - the closing quote included - because every
         * preview slot carries title="N of M places left" while the "Next open
         * slot" card in the sidebar says the same words without a title. Counting
         * the bare phrase counted that card too and made this test lie.
         */
        $this->assertSame(
            1,
            substr_count($html, 'places left"'),
            'The preview still lists every day at once, which is the reported wall.',
        );

        // ...but the later day is still offered, one click away in the strip.
        $this->assertStringContainsString('date='.$later, $html);
        $this->assertStringContainsString('aria-label="Choose a day"', $html);
    }

    public function test_the_detail_page_can_be_pointed_at_a_later_day(): void
    {
        $later = today()->addDays(4)->toDateString();

        $this->slot(today()->addDay()->toDateString(), '09:00:00');
        $this->slot($later, '10:00:00');

        $html = $this->get('/services/CERT-IND?date='.$later)->assertOk()->getContent();

        // One slot either way - a different day, not a different count.
        $this->assertSame(1, substr_count($html, 'places left"'));

        // The earlier day is still in the strip.
        $this->assertStringContainsString('date='.today()->addDay()->toDateString(), $html);
    }

    /** A day that cannot be booked on must not produce an empty preview. */
    public function test_the_detail_page_ignores_a_bogus_date(): void
    {
        $this->slot(today()->addDay()->toDateString(), '09:00:00');

        $response = $this->get('/services/CERT-IND?date=1999-01-01')->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'places left"'));
    }

    public function test_the_detail_page_hides_closed_holiday_slots(): void
    {
        $date = today()->addDays(3);
        $this->slot($date->toDateString());
        Holiday::create(['date' => $date->toDateString(), 'name' => 'Office Maintenance']);

        $this->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee('No open slots in the coming weeks');
    }

    public function test_an_inactive_service_detail_page_is_a_404(): void
    {
        $this->service->update(['is_active' => false]);

        $this->get('/services/CERT-IND')->assertNotFound();
    }

    public function test_the_detail_page_explains_the_priority_verification_rule(): void
    {
        // The project's stated limitation must be visible to clients.
        $this->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee('RA 9994')
            ->assertSee('RA 10754')
            ->assertSee('after staff verify');
    }

    /**
     * The catalogue only renders the call to action when a service has open
     * slots, so each of these needs one.
     */
    private function serviceWithOpenSlot(): Service
    {
        $window = ServiceWindow::factory()->create();
        $service = Service::factory()->create(['service_window_id' => $window->id, 'is_active' => true]);
        $start = now()->addHour()->startOfHour();

        TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $window->id,
            'slot_date' => today()->addDay()->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => today()->addDay()->setTime($start->hour, $start->minute)->toDateTimeString(),
            'end_at' => today()->addDay()->setTime($start->hour, $start->minute)->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        return $service;
    }

    public function test_a_staff_member_is_offered_a_disabled_label_instead_of_a_booking_link(): void
    {
        // "Book a slot" used to render for any signed-in user, and the booking
        // route is client-only, so staff followed it into a bare 403.
        $this->serviceWithOpenSlot();

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('services.index'))
            ->assertOk()
            ->assertSee('Client accounts only')
            ->assertDontSee('Book a slot');
    }

    public function test_an_administrator_is_offered_a_disabled_label_instead_of_a_booking_link(): void
    {
        $this->serviceWithOpenSlot();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('services.index'))
            ->assertOk()
            ->assertSee('Client accounts only')
            ->assertDontSee('Book a slot');
    }

    public function test_a_client_still_sees_the_booking_link(): void
    {
        $this->serviceWithOpenSlot();

        $this->actingAs(User::factory()->client()->create())
            ->get(route('services.index'))
            ->assertOk()
            ->assertSee('Book a slot')
            ->assertDontSee('Client accounts only');
    }

    public function test_guests_are_prompted_to_register(): void
    {
        $this->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee('Create a free client account')
            ->assertSee(route('register'))
            ->assertSee(route('login'));
    }

    /**
     * A signed-in client must never be told to sign in.
     *
     * This previously asserted the opposite - the page showed "Sign in to book"
     * to clients who were already authenticated, so booking looked impossible.
     * The copy asserted here is the corrected call to action.
     */
    public function test_a_signed_in_client_sees_the_signed_in_call_to_action(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee('Book this service')
            ->assertDontSee('Sign in to book')
            ->assertDontSee('Sign in as a client to reserve a slot');
    }

    public function test_a_service_card_is_itself_a_link_to_the_service(): void
    {
        /*
         * The only way into a service used to be a small button at the bottom of
         * the card, so a resident scanning the list on a phone had to aim at a
         * finger-sized target to find out what a service was.
         *
         * Asserted structurally rather than by counting links: the card must be
         * one link with one name and one tab stop, which is what the stretched
         * ::after pattern gives and what wrapping the card in another <a> would
         * not.
         */
        $this->serviceWithOpenSlot();

        $content = $this->get(route('services.index'))->assertOk()->getContent();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($content);
        libxml_clear_errors();

        $xp = new \DOMXPath($dom);

        $detail = $xp->query('//a[contains(@class, "after:absolute")]');

        $this->assertGreaterThan(0, $detail->length, 'The service name should be the card link.');

        // Every card, not just one: the fixture lists more than one service, and
        // a card that was not clickable would be invisible to a text assertion.
        $hrefs = [];

        foreach ($detail as $link) {
            $this->assertStringStartsWith(url('/services/'), $link->getAttribute('href'));
            $this->assertStringContainsString('view times and book', $link->textContent);

            $hrefs[] = $link->getAttribute('href');
        }

        $this->assertContains(route('services.show', $this->service), $hrefs);

        // One link per card, not two nested ones.
        $this->assertSame(
            0,
            $xp->query('//a[contains(@class, "after:absolute")]/a')->length,
            'A link must not wrap another link.'
        );
    }

    public function test_the_booking_button_sits_above_the_card_link(): void
    {
        // Both are links to different places. Without the stacking the button
        // would inherit the card's click target and open the detail page
        // instead of the booking form.
        $this->serviceWithOpenSlot();

        $this->actingAs(User::factory()->client()->create())
            ->get(route('services.index'))
            ->assertOk()
            ->assertSee('relative z-10', escape: false);
    }
}
