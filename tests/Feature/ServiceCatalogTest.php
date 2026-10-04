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

    public function test_guests_are_prompted_to_register(): void
    {
        $this->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee('Create a free client account')
            ->assertSee(route('register'))
            ->assertSee(route('login'));
    }

    public function test_a_signed_in_client_sees_the_signed_in_call_to_action(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/services/CERT-IND')
            ->assertOk()
            ->assertSee('Sign in as a client to reserve a slot');
    }
}
