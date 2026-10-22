<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Choosing a slot should not mean scrolling past a wall of them.
 *
 * Reported: with a lot of time slots, step 1 runs off the bottom of the screen,
 * steps 2 and 3 sit below it, and a client arriving from "Book this service" can
 * reach the confirm button without ever seeing there was a step 1.
 *
 * The page used to render every open slot across the whole booking horizon in
 * one column, and the selection banner's own advice was to "scroll down to
 * complete your booking" - an accurate description of the problem rather than a
 * response to it.
 */
class BookingSlotChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $window = ServiceWindow::factory()->create(['code' => 'WINDOW_1']);

        $this->service = Service::factory()->create([
            'service_window_id' => $window->id,
            'code' => 'CERT-IND',
            'name' => 'Certificate of Indigency',
            'is_active' => true,
        ]);

        $this->client = User::factory()->client()->create();
    }

    /** Six open slots on one day. */
    private function slotsOn(string $date, int $count = 6): void
    {
        for ($i = 0; $i < $count; $i++) {
            $start = Carbon::parse($date)->setTime(9, $i * 30);

            TimeSlot::create([
                'service_id' => $this->service->id,
                'service_window_id' => $this->service->service_window_id,
                'slot_date' => $start->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
                'start_at' => $start->toDateTimeString(),
                'end_at' => $start->copy()->addMinutes(30)->toDateTimeString(),
                'capacity' => 4,
                'booked_count' => 0,
                'is_open' => true,
            ]);
        }
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->client)->get(route('client.booking.create', [$this->service] + $query));
    }

    /* ------------------------------------------------------------------ */

    public function test_the_page_opens_on_the_first_day_not_on_every_day(): void
    {
        $this->slotsOn(today()->addDay()->toDateString());
        $this->slotsOn(today()->addDays(3)->toDateString());
        $this->slotsOn(today()->addDays(5)->toDateString());

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('id="day-'.today()->addDay()->toDateString().'"', $html);
        $this->assertStringNotContainsString(
            'id="day-'.today()->addDays(3)->toDateString().'"',
            $html,
            'The page still renders every day on first load, which is the reported wall.',
        );
        $this->assertStringNotContainsString('id="day-'.today()->addDays(5)->toDateString().'"', $html);
    }

    /**
     * There is no longer a way back to the wall.
     *
     * An earlier version offered "Show every day instead", which left the
     * reported problem one click away and still reachable at will.
     */
    public function test_there_is_no_way_back_to_every_day(): void
    {
        $this->slotsOn(today()->addDay()->toDateString());
        $this->slotsOn(today()->addDays(3)->toDateString());

        $this->page()
            ->assertOk()
            ->assertDontSee('Show every day instead')
            ->assertDontSee('Show all days');
    }

    public function test_choosing_a_day_renders_only_that_day(): void
    {
        $this->slotsOn(today()->addDay()->toDateString());
        $this->slotsOn(today()->addDays(3)->toDateString());
        $this->slotsOn(today()->addDays(5)->toDateString());

        $chosen = today()->addDays(3)->toDateString();

        $html = $this->page(['date' => $chosen])->assertOk()->getContent();

        $this->assertStringContainsString('id="day-'.$chosen.'"', $html);
        $this->assertStringNotContainsString('id="day-'.today()->addDay()->toDateString().'"', $html);
        $this->assertStringNotContainsString('id="day-'.today()->addDays(5)->toDateString().'"', $html);
    }

    public function test_the_day_strip_offers_every_day_even_when_one_is_shown(): void
    {
        $this->slotsOn(today()->addDay()->toDateString());
        $this->slotsOn(today()->addDays(3)->toDateString());

        $html = $this->page(['date' => today()->addDays(3)->toDateString()])->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Choose a day"', $html);

        // Each day in the strip is a link, so a day can be reached with no
        // JavaScript at all.
        $this->assertStringContainsString(
            'date='.today()->addDay()->toDateString(),
            $html,
            'The day strip is missing a day, so a client cannot get back to it without JavaScript.',
        );
    }

    public function test_the_strip_says_how_many_slots_each_day_has(): void
    {
        $this->slotsOn(today()->addDay()->toDateString(), count: 5);

        $this->page()
            ->assertOk()
            ->assertSee('5 free');
    }

    /**
     * Step 1 was missable because the button to finish sat below it.
     *
     * The button now refuses to submit until a slot is chosen, and says so,
     * because a disabled button on its own explains nothing.
     */
    public function test_confirming_is_refused_until_a_slot_is_chosen(): void
    {
        $this->slotsOn(today()->addDay()->toDateString());

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString(
            'x-bind:disabled="! selectedSlot"',
            $html,
            'The confirm button is not gated on a slot being chosen.',
        );

        $this->assertStringContainsString('Choose a time in step 1 first.', $html);
    }

    public function test_the_chosen_slot_is_summarised_beside_the_confirm_button(): void
    {
        $this->slotsOn(today()->addDay()->toDateString());

        $html = $this->page()->assertOk()->getContent();

        // Previously the only confirmation of a selection was a banner at the
        // top of a long page, and the panel the button lives in did not mention
        // it at all.
        $this->assertStringContainsString('Your slot', $html);
        $this->assertStringContainsString('Not chosen yet', $html);
    }

    public function test_the_page_no_longer_tells_the_reader_to_scroll_down(): void
    {
        $this->slotsOn(today()->addDay()->toDateString());

        $this->page()
            ->assertOk()
            ->assertDontSee('Scroll down to complete your booking');
    }

    public function test_a_day_with_no_free_slots_is_labelled_full(): void
    {
        $date = today()->addDay()->toDateString();
        $this->slotsOn($date, count: 1);

        $slot = TimeSlot::where('service_id', $this->service->id)->first();
        $slot->update(['capacity' => 1, 'booked_count' => 1]);

        $this->page()->assertOk()->assertSee('full');
    }
}
