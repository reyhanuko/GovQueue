<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Mail\AppointmentConfirmedMail;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The client can see what a service costs, and what they paid for it.
 *
 * The fee was on the booking screen and nowhere else. The confirmation did not
 * mention it, the appointment page did not mention it, and after the
 * transaction the amount actually recorded at the counter - with a receipt
 * reference - was not visible to the person who paid it either. It was in the
 * system, in the records the reports sum, and in no hand the client could hold.
 */
class ClientFeeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Service $service;

    private ServiceWindow $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create();
        $this->window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);
        $this->service = Service::factory()->create([
            'service_window_id' => $this->window->id,
            'fee' => 50,
        ]);
    }

    private function appointment(string $status): Appointment
    {
        $start = Carbon::parse(today()->addDay()->toDateString())->setTime(9, 0);

        $slot = TimeSlot::create([
            'service_id' => $this->service->id,
            'service_window_id' => $this->window->id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => 10,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        return Appointment::factory()->create([
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'time_slot_id' => $slot->id,
            'service_window_id' => $this->window->id,
            'scheduled_at' => $start->toDateTimeString(),
            'queue_date' => $start->toDateString(),
            'status' => $status,
        ]);
    }

    public function test_an_upcoming_appointment_says_what_the_service_costs(): void
    {
        $appointment = $this->appointment(AppointmentStatus::Confirmed->value);

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('50.00')
            ->assertSee('payable at the counter');
    }

    public function test_a_completed_appointment_shows_what_was_paid_and_the_receipt(): void
    {
        $appointment = $this->appointment(AppointmentStatus::Completed->value);

        $staff = User::factory()->staff()->create(['service_window_id' => $this->window->id]);

        Transaction::create([
            'reference_number' => 'TX-260918-00042-AB7C',
            'appointment_id' => $appointment->id,
            'user_id' => $this->client->id,
            'service_id' => $this->service->id,
            'handled_by' => $staff->id,
            'fee_paid' => 35,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('35.00')
            ->assertSee('paid')
            ->assertSee('TX-260918-00042-AB7C');
    }

    public function test_a_free_service_says_so(): void
    {
        $this->service->update(['fee' => 0]);

        $appointment = $this->appointment(AppointmentStatus::Confirmed->value);

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Free')
            ->assertDontSee('payable at the counter');
    }

    public function test_a_cancelled_appointment_does_not_ask_for_money(): void
    {
        $appointment = $this->appointment(AppointmentStatus::Cancelled->value);

        $this->actingAs($this->client)
            ->get(route('client.appointments.show', $appointment))
            ->assertOk()
            ->assertDontSee('payable at the counter');
    }

    public function test_the_confirmation_email_states_the_fee(): void
    {
        Mail::fake();

        $appointment = $this->appointment(AppointmentStatus::Confirmed->value);

        $rendered = (new AppointmentConfirmedMail($appointment))->render();

        $this->assertStringContainsString('50.00', $rendered);
        $this->assertStringContainsString('payable at the counter', $rendered);
    }
}
