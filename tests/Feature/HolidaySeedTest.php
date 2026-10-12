<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeding the national holiday calendar from the admin screen.
 */
class HolidaySeedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_an_administrator_can_seed_the_regular_holidays_for_a_year(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.holidays.seed'), ['year' => 2026])
            ->assertRedirect();

        $this->assertSame("New Year's Day", Holiday::where('date', '2026-01-01')->first()?->name);
        $this->assertNotNull(Holiday::where('date', '2026-05-01')->first(), 'Labor Day');
        $this->assertNotNull(Holiday::where('date', '2026-04-03')->first(), 'Good Friday');
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->actingAs($this->admin)->post(route('admin.holidays.seed'), ['year' => 2026]);

        $before = Holiday::orderBy('date')->pluck('name', 'date')->all();

        $this->actingAs($this->admin)->post(route('admin.holidays.seed'), ['year' => 2026]);

        $this->assertSame($before, Holiday::orderBy('date')->pluck('name', 'date')->all());
        $this->assertSame(count($before), Holiday::where('date', 'like', '2026-%')->count());
    }

    public function test_the_second_run_says_so_visibly(): void
    {
        $this->actingAs($this->admin)->post(route('admin.holidays.seed'), ['year' => 2026]);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.holidays.index'))
            ->post(route('admin.holidays.seed'), ['year' => 2026]);

        $response->assertSessionHas('hint');

        // Regression: a hint flashed without an accompanying error was never
        // rendered at all, so the button looked like it had done nothing.
        $response->assertRedirect(route('admin.holidays.index'));
        $this->actingAs($this->admin)
            ->get(route('admin.holidays.index'))
            ->assertSee('Nothing changed');
    }

    public function test_a_hand_added_closure_is_never_overwritten(): void
    {
        Holiday::create([
            'date' => '2026-05-01',
            'name' => 'Local charter day',
            'notes' => 'Declared by the municipal board.',
        ]);

        $this->actingAs($this->admin)->post(route('admin.holidays.seed'), ['year' => 2026]);

        $local = Holiday::where('date', '2026-05-01')->first();

        $this->assertSame('Local charter day', $local->name);
        $this->assertSame('Declared by the municipal board.', $local->notes);

        // The rest of the year still gets seeded.
        $this->assertNotNull(Holiday::where('date', '2026-01-01')->first());
    }

    public function test_seeding_is_refused_for_a_nonsensical_year(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.holidays.index'))
            ->post(route('admin.holidays.seed'), ['year' => 1800])
            ->assertSessionHasErrors('year');
    }

    public function test_a_client_cannot_seed_holidays(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->post(route('admin.holidays.seed'), ['year' => 2026])
            ->assertForbidden();
    }
}