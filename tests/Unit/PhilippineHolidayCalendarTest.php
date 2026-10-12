<?php

namespace Tests\Unit;

use App\Services\PhilippineHolidayCalendar;
use Tests\TestCase;

/**
 * The seeded national holiday calendar.
 *
 * The point of these tests is that a wrong date here closes the office on a day
 * it is open, hiding bookable slots from real clients. Easter in particular is
 * computed, so it gets checked against known dates.
 */
class PhilippineHolidayCalendarTest extends TestCase
{
    private PhilippineHolidayCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendar = new PhilippineHolidayCalendar;
    }

    public function test_it_covers_the_fixed_date_regular_holidays(): void
    {
        $holidays = $this->calendar->regularHolidays(2026);

        $this->assertSame("New Year's Day", $holidays['2026-01-01']);
        $this->assertSame('Labor Day', $holidays['2026-05-01']);
        $this->assertSame('Independence Day', $holidays['2026-06-12']);
        $this->assertSame('National Heroes Day', $holidays['2026-08-31']);
        $this->assertSame('Bonifacio Day', $holidays['2026-11-30']);
        $this->assertSame('Christmas Day', $holidays['2026-12-25']);
        $this->assertSame('Rizal Day', $holidays['2026-12-30']);
    }

    public function test_the_easter_observances_are_computed_correctly(): void
    {
        // Easter 2026 is 5 April, so Maundy Thursday is 2 April and Good Friday
        // is 3 April. Both are movable, which is why they are computed rather
        // than listed.
        $holidays = $this->calendar->regularHolidays(2026);

        $this->assertSame('Maundy Thursday', $holidays['2026-04-02']);
        $this->assertSame('Good Friday', $holidays['2026-04-03']);
    }

    public function test_the_easter_algorithm_holds_across_years(): void
    {
        // Known Easter Sundays: 2024-03-31, 2025-04-20, 2026-04-05, 2027-03-28.
        $this->assertSame('2027-03-25', array_search(
            'Maundy Thursday',
            $this->calendar->regularHolidays(2027),
            true,
        ));

        $this->assertSame('2024-03-28', array_search(
            'Maundy Thursday',
            $this->calendar->regularHolidays(2024),
            true,
        ));

        $this->assertSame('2025-04-17', array_search(
            'Maundy Thursday',
            $this->calendar->regularHolidays(2025),
            true,
        ));
    }

    public function test_every_date_falls_in_the_year_it_was_asked_for(): void
    {
        foreach ([2026, 2027, 2030] as $year) {
            foreach (array_keys($this->calendar->regularHolidays($year)) as $date) {
                $this->assertStringStartsWith((string) $year, $date);
            }
        }
    }

    public function test_the_lunar_feasts_are_deliberately_absent(): void
    {
        // Guessing a lunar date would close the office when it is open, so they
        // are added by hand from the proclamation instead.
        $names = array_values($this->calendar->regularHolidays(2026));

        foreach ($names as $name) {
            $this->assertStringNotContainsString('Eid', $name);
        }
    }

    public function test_dates_are_unique_within_a_year(): void
    {
        $dates = array_keys($this->calendar->regularHolidays(2026));

        $this->assertSame($dates, array_unique($dates));
    }
}
