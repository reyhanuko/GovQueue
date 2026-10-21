<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\PriorityType;
use App\Livewire\Staff\QueueConsole;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A claim and a decision are two different facts, and the screen used to
 * confuse them.
 *
 * `Appointment::priorityLabels()` returned "Regular" for anything that had
 * not been verified yet. So a client who had claimed a PWD entitlement was
 * told - on their own appointment page, on the page they watch while they
 * wait, and by the clerk who had opened the panel to verify the claim - that
 * they had claimed nothing at all.
 *
 * The claim is real the moment it is made. What is undecided is whether it
 * is granted, and that is a different sentence.
 */
class PriorityClaimDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create(['name' => 'Rosa Lim']);
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create(['service_window_id' => $this->window->id]);
    }

    private int $slotCounter = 0;

    private function appointment(array $overrides = []): Appointment
    {
        $this->slotCounter++;
        $start = today()->setTime(9, 0)->addMinutes(($this->slotCounter - 1) * 30);

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => today()->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 0,
            'is_open' => true,
        ]);

        return Appointment::create(array_merge([
            'reference_number' => 'GQ-D-'.uniqid(),
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'status' => AppointmentStatus::Confirmed,
            'source' => 'online',
            'scheduled_at' => $slot->start_at,
            'queue_date' => today()->toDateString(),
            'queue_number' => 'A-001',
            'claims_pwd' => true,
            'priority_reference' => 'PWD-12345',
        ], $overrides));
    }

    public function test_a_client_who_claimed_pwd_is_told_they_claimed_pwd(): void
    {
        $appointment = $this->appointment();

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Person with Disability')
            ->assertDontSee('You claimed: <strong>Regular');
    }

    public function test_the_list_of_appointments_names_the_claim(): void
    {
        $appointment = $this->appointment();

        $this->actingAs($this->client)
            ->get(route('client.appointments.index'))
            ->assertOk()
            ->assertSee('Person with Disability')
            ->assertDontSee('Regular');
    }

    public function test_the_live_queue_page_names_the_claim(): void
    {
        $appointment = $this->appointment(['status' => AppointmentStatus::CheckedIn]);

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Person with Disability')
            ->assertDontSee('Priority claimed: <strong>Regular');
    }

    public function test_a_client_with_no_claim_is_still_a_regular_client(): void
    {
        $appointment = $this->appointment([
            'claims_pwd' => false,
            'priority_reference' => null,
        ]);

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk()
            ->assertDontSee('Priority claim');
    }

    public function test_the_status_label_says_what_state_the_claim_is_in(): void
    {
        $unverified = $this->appointment();

        $this->assertSame(
            'Person with Disability (awaiting verification)',
            $unverified->priorityStatusLabel(),
        );

        $verified = $this->appointment(['priority_verified_at' => now()]);

        $this->assertSame(
            'Person with Disability (verified)',
            $verified->priorityStatusLabel(),
        );

        $refused = $this->appointment([
            'priority_refused_at' => now(),
            'priority_refused_reason' => 'No ID presented',
        ]);

        $this->assertSame(
            'Person with Disability (refused)',
            $refused->priorityStatusLabel(),
        );

        $none = $this->appointment(['claims_pwd' => false]);

        $this->assertSame('Regular', $none->priorityStatusLabel());
    }

    public function test_the_console_names_the_claim_it_is_asking_about(): void
    {
        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);
        $appointment = $this->appointment(['status' => AppointmentStatus::CheckedIn]);

        Livewire::actingAs($staff)
            ->test(QueueConsole::class, ['windowCode' => 'WINDOW_1'])
            ->call('openVerify', $appointment->id)
            ->assertSee('Person with Disability')
            ->assertDontSee('claims <strong>Regular');
    }

    public function test_the_claimed_label_is_the_claim_not_the_grant(): void
    {
        $appointment = $this->appointment();

        $this->assertSame('Person with Disability', $appointment->claimedPriorityLabels());
        $this->assertSame('', $appointment->verifiedPriorityLabels());
    }

    public function test_the_short_label_is_used_where_a_line_has_to_fit(): void
    {
        $this->assertSame('PWD', PriorityType::Pwd->shortLabel());
        $this->assertSame('Pregnant', PriorityType::Pregnant->shortLabel());
    }
}
