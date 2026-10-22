<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Abuse limits on the booking endpoints.
 *
 * Booking and rescheduling were unthrottled. The row lock already makes any single
 * booking safe, but it says nothing about how many a script may make, and the
 * practical effect was that one client could cycle places through the day's slots
 * in seconds - taking a place, moving it, taking it again - before anyone else had
 * a chance to see the board.
 *
 * The limiter keys on the signed-in user, which is the property that makes this
 * safe to ship: a shared counter would let one resident lock the whole
 * municipality out of booking for a minute.
 */
class BookingRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->client()->create([
            'phone' => '09171234567',
            'birthdate' => '1995-04-12',
        ]);

        $this->clearLimiter($this->client);
    }

    /**
     * Clear this user's booking counter so a test cannot inherit a count filled by
     * an earlier one. TestCase disables key hashing, so the key is the readable
     * "<limiter name>:<declared key>" the framework builds from AppServiceProvider.
     */
    private function clearLimiter(User ...$users): void
    {
        foreach ($users as $user) {
            RateLimiter::clear('booking:booking|'.$user->getAuthIdentifier());
        }
    }

    private function service(string $code): Service
    {
        return Service::factory()->create(['code' => $code, 'is_active' => true]);
    }

    /** Each slot needs its own start time: time_slots is unique per service+date+start. */
    private function slot(Service $service, int $hour, int $capacity = 3): TimeSlot
    {
        $start = now()->addDay()->setTime($hour, 0);

        return TimeSlot::create([
            'service_id' => $service->id,
            'service_window_id' => $service->service_window_id,
            'slot_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'start_at' => $start->toDateTimeString(),
            'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
            'capacity' => $capacity,
            'booked_count' => 0,
            'is_open' => true,
        ]);
    }

    public function test_a_client_can_still_book_normally(): void
    {
        // The ceiling has to sit well above what one person plausibly does. A
        // resident comparing slots, or reloading after a failed submit, must not
        // be turned away by the very protection meant for the office.
        $service = $this->service('RL-OK');

        $this->actingAs($this->client)
            ->post('/services/RL-OK/book', ['time_slot_id' => $this->slot($service, 9)->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Appointment::count());
    }

    public function test_booking_repeatedly_is_throttled(): void
    {
        // One service per attempt, each with its own slot, so the "one live booking
        // per service" rule never fires. That leaves the limiter as the only thing
        // that can stop it - which is exactly what this asserts.
        for ($i = 0; $i < 14; $i++) {
            $code = 'RL-LOOP-'.$i;
            $service = $this->service($code);

            $response = $this->actingAs($this->client)
                ->post('/services/'.$code.'/book', [
                    'time_slot_id' => $this->slot($service, 9 + $i)->id,
                ]);

            if ($response->getStatusCode() === 429) {
                $this->assertGreaterThanOrEqual(
                    10,
                    $i,
                    'The client was throttled before the advertised allowance of 10.'
                );
                $this->assertLessThanOrEqual(
                    11,
                    $i,
                    'The client was throttled later than the advertised allowance of 10.'
                );

                return;
            }

            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                'Attempt '.$i.' failed for a reason other than the throttle: '
                .json_encode($response->getSession()->all()['errors'] ?? [])
            );
        }

        $this->fail('Booking was never throttled across 14 attempts against 14 services.');
    }

    public function test_the_throttle_leaves_no_capacity_behind(): void
    {
        // The half-applied-write failure mode: if the limiter fired after the
        // booking transaction began, booked_count would climb with no appointment
        // behind it and the slot would look fuller than it is.
        for ($i = 0; $i < 14; $i++) {
            $code = 'RL-LEAK-'.$i;
            $service = $this->service($code);
            $slot = $this->slot($service, 9 + $i, capacity: 1);

            $this->actingAs($this->client)
                ->post('/services/'.$code.'/book', ['time_slot_id' => $slot->id]);
        }

        $checked = 0;

        foreach (TimeSlot::whereIn('service_id', Service::where('code', 'like', 'RL-LEAK-%')->pluck('id'))->get() as $slot) {
            $live = Appointment::where('time_slot_id', $slot->id)
                ->whereIn('status', ['pending', 'confirmed', 'checked_in', 'serving'])
                ->count();

            $this->assertSame(
                $live,
                (int) $slot->booked_count,
                'Slot '.$slot->id.' has a counter that no longer matches its live appointments.'
            );

            $checked++;
        }

        $this->assertSame(14, $checked);
    }

    public function test_one_client_cannot_lock_another_out(): void
    {
        // The property that makes the limiter safe to ship at all.
        $noisy = User::factory()->client()->create(['phone' => '09171234568']);
        $quiet = User::factory()->client()->create(['phone' => '09171234569']);

        $this->clearLimiter($noisy);
        $this->clearLimiter($quiet);

        for ($i = 0; $i < 14; $i++) {
            $code = 'RL-NOISY-'.$i;
            $service = $this->service($code);

            $this->actingAs($noisy)->post('/services/'.$code.'/book', [
                'time_slot_id' => $this->slot($service, 9 + $i)->id,
            ]);
        }

        $service = $this->service('RL-QUIET');
        $slot = $this->slot($service, 9);

        $this->actingAs($quiet)
            ->post('/services/RL-QUIET/book', ['time_slot_id' => $slot->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, (int) $slot->fresh()->booked_count);
    }

    public function test_the_booking_screen_itself_is_not_throttled(): void
    {
        // Only the mutating POST is capped. Throttling the GET would break a
        // client who presses back, reloads, or opens the slot grid twice - all
        // ordinary on a slow connection or a shared device at the counter.
        $service = $this->service('RL-GET');

        for ($i = 0; $i < 15; $i++) {
            $this->actingAs($this->client)
                ->get('/services/RL-GET/book')
                ->assertOk();
        }
    }

    public function test_rescheduling_is_capped_too(): void
    {
        // Reschedule releases one place and claims another, so an uncapped version
        // of this loop cycles capacity through slots just as effectively as
        // booking does.
        $service = $this->service('RL-MOVE');
        $appointment = Appointment::factory()->create([
            'user_id' => $this->client->id,
            'service_id' => $service->id,
            'status' => 'confirmed',
        ]);

        for ($i = 0; $i < 14; $i++) {
            $hour = 8 + $i;

            $response = $this->actingAs($this->client)->put(
                '/my-appointments/'.$appointment->id.'/move',
                ['time_slot_id' => $this->slot($service, $hour)->id]
            );

            if ($response->getStatusCode() === 429) {
                $this->assertGreaterThanOrEqual(
                    10,
                    $i,
                    'The reschedule was throttled before the advertised allowance of 10.'
                );

                return;
            }
        }

        $this->fail('Rescheduling was never throttled across 14 attempts.');
    }
}
