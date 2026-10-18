<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin closures screen: what it shows, and what it refuses to store.
 */
class HolidayIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_past_closures_are_hidden_from_the_default_view(): void
    {
        Holiday::create(['date' => today()->subYear()->toDateString(), 'name' => 'Old Closure']);

        $response = $this->actingAs($this->admin)->get(route('admin.holidays.index'));

        $response->assertOk();
        $response->assertDontSee('Old Closure');
        $response->assertSee('Show past closures (1)');
    }

    public function test_past_closures_are_reachable_on_request(): void
    {
        Holiday::create(['date' => today()->subYear()->toDateString(), 'name' => 'Old Closure']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.holidays.index', ['show_past' => 1]));

        $response->assertOk();
        $response->assertSee('Old Closure');
    }

    public function test_an_upcoming_closure_is_always_shown(): void
    {
        Holiday::create(['date' => today()->addMonths(2)->toDateString(), 'name' => 'Future Closure']);

        $response = $this->actingAs($this->admin)->get(route('admin.holidays.index'));

        $response->assertOk();
        $response->assertSee('Future Closure');
    }

    /**
     * Regression: `date` is cast to a date and so materialises as midnight. The
     * old view asked isPast(), which is true from 00:00 onward, so a closure on
     * today was badged "Past" and skipped by the affected-clients query - on the
     * one screen that answers "which clients need rebooking?".
     */
    public function test_a_closure_on_today_is_not_treated_as_past(): void
    {
        Holiday::create(['date' => today()->toDateString(), 'name' => 'Today Closure']);

        $response = $this->actingAs($this->admin)->get(route('admin.holidays.index'));

        $response->assertOk();
        $response->assertSee('Today Closure');
        $response->assertSee('Today');
    }

    public function test_closures_are_grouped_by_year(): void
    {
        $nextYear = now()->addYear()->startOfYear()->addDay();

        Holiday::create(['date' => today()->addMonth()->toDateString(), 'name' => 'This Year One']);
        Holiday::create(['date' => $nextYear->toDateString(), 'name' => 'Next Year One']);

        $response = $this->actingAs($this->admin)->get(route('admin.holidays.index'));

        $response->assertOk();
        // The year headings themselves, not just the names.
        $response->assertSee(now()->year, escape: false);
        $response->assertSee($nextYear->year, escape: false);
    }

    public function test_a_past_date_cannot_be_declared(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.holidays.index'))
            ->post(route('admin.holidays.store'), [
                'date' => today()->subDay()->toDateString(),
                'name' => 'Yesterday',
            ]);

        $response->assertRedirect(route('admin.holidays.index'));
        $response->assertSessionHasErrors('date');

        $this->assertSame(0, Holiday::where('date', today()->subDay()->toDateString())->count());
    }

    public function test_today_can_still_be_declared(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.holidays.store'), [
                'date' => today()->toDateString(),
                'name' => 'Closed Today',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Holiday::where('date', today()->toDateString())->first());
    }

    public function test_a_year_that_has_already_passed_cannot_be_seeded(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.holidays.index'))
            ->post(route('admin.holidays.seed'), ['year' => now()->year - 1]);

        $response->assertRedirect(route('admin.holidays.index'));
        $response->assertSessionHasErrors('year');

        $this->assertSame(0, Holiday::where('date', 'like', (now()->year - 1).'-%')->count());
    }

    /**
     * "Declare a closure" is this screen's primary verb, so it must not be
     * buried under the list. An earlier revision put the forms last, which did
     * not remove the scrolling - it only inverted it, so adding a closure meant
     * scrolling past every existing row to reach the button.
     */
    public function test_the_declaration_form_comes_before_the_closures(): void
    {
        Holiday::create(['date' => today()->addMonth()->toDateString(), 'name' => 'Soonest Closure']);

        $html = $this->actingAs($this->admin)->get(route('admin.holidays.index'))
            ->assertOk()->getContent();

        $formPosition = strpos($html, 'Save closure');
        $listPosition = strpos($html, 'Upcoming closures');

        $this->assertNotFalse($formPosition, 'The declaration form is missing.');
        $this->assertNotFalse($listPosition, 'The closures heading is missing.');
        $this->assertLessThan(
            $listPosition,
            $formPosition,
            'The declaration form must come before the list, or the primary action is below the fold.',
        );
    }

    /**
     * Only the seeder collapses. It is a bulk import somebody runs once, so it
     * earns its space back as a single summary line - but the daily form stays
     * open, because hiding it behind a click is what made this page awkward.
     */
    public function test_only_the_seeder_is_collapsed(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.holidays.index'))
            ->assertOk()->getContent();

        $formPosition = strpos($html, 'Declare a closure');
        $savePosition = strpos($html, 'Save closure');
        $seederPosition = strpos($html, 'Add the national holidays');

        $this->assertNotFalse($formPosition);
        $this->assertNotFalse($savePosition);
        $this->assertNotFalse($seederPosition);

        // Were the daily form collapsed, a <details> + <summary> pair would sit
        // between its own heading and its button. Scoped to that slice rather
        // than the whole document, because the shared navbar has a disclosure
        // of its own.
        $this->assertStringNotContainsString(
            '<details',
            substr($html, $formPosition, $savePosition - $formPosition),
            'The declaration form is inside a disclosure; it is the primary action and must stay open.',
        );

        // The seeder is the one that collapses.
        $this->assertStringContainsString(
            '<details',
            substr($html, $seederPosition - 400, 400),
            'The national-holidays seeder should be a collapsed disclosure.',
        );
    }

    public function test_the_seeder_starts_collapsed(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.holidays.index'))
            ->assertOk()->getContent();

        // A bare <details ...> with no `open` attribute.
        $this->assertMatchesRegularExpression('/<details(?![^>]*\bopen\b)[^>]*>/', $html);
    }

    /**
     * A rejected submit must not leave the offending field hidden inside a
     * collapsed box - the error would look as though it had vanished.
     */
    public function test_the_seed_form_reopens_when_the_year_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.holidays.index'))
            ->post(route('admin.holidays.seed'), ['year' => now()->year - 5])
            ->assertSessionHasErrors('year');

        $html = $this->actingAs($this->admin)
            ->get(route('admin.holidays.index'))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<details[^>]*\bopen\b[^>]*>/', $html);
    }

    /**
     * The reason past closures collapse by year. This list grows ~12 rows a year,
     * forever, and it sits below the fold - so every year it pushed the actionable
     * upcoming list further down the page. Grouped into year summaries it stays a
     * handful of lines however far back it goes, and nothing is deleted.
     */
    public function test_past_closures_collapse_into_one_disclosure_per_year(): void
    {
        // Three years of history: 12 holidays a year is a realistic steady state.
        foreach ([2023, 2024, 2025] as $year) {
            foreach (['01-01', '05-01', '12-25'] as $monthDay) {
                Holiday::create(['date' => "$year-$monthDay", 'name' => "Closure $monthDay"]);
            }
        }

        $this->assertSame(9, Holiday::count());

        $html = $this->actingAs($this->admin)
            ->get(route('admin.holidays.index', ['show_past' => 1]))
            ->assertOk()->getContent();

        $pastSection = substr($html, strpos($html, 'Past closures'));

        // Three years, three collapsed disclosures - not nine stacked rows.
        $this->assertSame(3, substr_count($pastSection, '<details'));
        $this->assertSame(0, substr_count($pastSection, '<details open'), 'Past years must start collapsed.');

        // The count is still stated, so the size of each year is knowable
        // without opening it.
        $this->assertStringContainsString('3 closures', $pastSection);
    }

    public function test_a_year_with_one_past_closure_reads_correctly(): void
    {
        Holiday::create(['date' => '2025-12-25', 'name' => 'Lone Closure']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.holidays.index', ['show_past' => 1]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('1 closure', substr($html, strpos($html, 'Past closures')));
        $this->assertStringNotContainsString('1 closures', $html);
    }

    /**
     * A past closure cannot be deleted.
     *
     * It looks inert because booking only reads closures from today onward, but
     * SlotGeneratorService::generate() takes every holiday row with no date filter
     * and DemoActivitySeeder generates slots 28 days into the past. Deleting one
     * would let a re-run emit slots for a day the office was recorded as shut. The
     * row is also the only remaining record that the day was closed.
     */
    public function test_a_past_closure_cannot_be_removed(): void
    {
        $holiday = Holiday::create([
            'date' => today()->subWeek()->toDateString(),
            'name' => 'Recorded Closure',
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.holidays.index', ['show_past' => 1]))
            ->delete(route('admin.holidays.destroy', $holiday));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertNotNull(Holiday::find($holiday->id), 'A past closure was deleted.');

        // And no audit entry claiming it was.
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'holiday.deleted',
            'subject_id' => $holiday->id,
        ]);
    }

    /**
     * Today is not in the past. The date column casts to midnight, so a naive
     * comparison would refuse to remove a closure on the day it was declared.
     */
    public function test_a_closure_on_today_can_still_be_removed(): void
    {
        $holiday = Holiday::create([
            'date' => today()->toDateString(),
            'name' => 'Closed Today',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.holidays.destroy', $holiday))
            ->assertSessionHas('success');

        $this->assertNull(Holiday::find($holiday->id));
    }

    public function test_an_upcoming_closure_can_still_be_removed(): void
    {
        $holiday = Holiday::create([
            'date' => today()->addMonth()->toDateString(),
            'name' => 'Future Closure',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.holidays.destroy', $holiday))
            ->assertSessionHas('success');

        $this->assertNull(Holiday::find($holiday->id));
    }

    public function test_the_past_list_offers_no_remove_control(): void
    {
        Holiday::create(['date' => today()->subWeek()->toDateString(), 'name' => 'Recorded Closure']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.holidays.index', ['show_past' => 1]))
            ->assertOk()->getContent();

        $pastSection = substr($html, strpos($html, 'Past closures'));

        $this->assertStringNotContainsString(
            'admin.holidays.destroy',
            $pastSection,
            'Past rows must not offer a Remove button the controller would refuse.',
        );
    }
}
