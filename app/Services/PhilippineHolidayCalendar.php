<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Philippine national holidays, for seeding the office closure calendar.
 *
 * Scope is deliberately narrow. This covers the fixed-date regular holidays in
 * RA 9492 plus the two that follow Easter, because those can be computed
 * exactly. The Islamic feasts (Eid'l Fitr, Eid'l Adha) are lunar and are NOT
 * included: guessing a date would close the office on a day it is open, which
 * hides bookable slots from real clients. An administrator adds those two by
 * hand, where the exact proclamation date can be entered.
 *
 * The same reasoning applies to "special non-working days": they are declared
 * yearly by proclamation, so there is no formula to seed and a stale list would
 * be worse than none.
 */
class PhilippineHolidayCalendar
{
    /**
     * Regular holidays for a year, as ['Y-m-d' => name].
     *
     * @return array<string, string>
     */
    public function regularHolidays(int $year): array
    {
        $holidays = [
            $year.'-01-01' => "New Year's Day",
            $year.'-04-09' => 'Araw ng Kagitingan (Day of Valor)',
            $year.'-05-01' => 'Labor Day',
            $year.'-06-12' => 'Independence Day',
            $year.'-08-31' => 'National Heroes Day',
            $year.'-11-01' => "All Saints' Day",
            $year.'-11-30' => 'Bonifacio Day',
            $year.'-12-25' => 'Christmas Day',
            $year.'-12-30' => 'Rizal Day',
            $year.'-12-31' => "New Year's Eve",
        ];

        // The two movable Christian observances follow Easter: Maundy Thursday
        // is three days before, Good Friday two days before.
        $easter = $this->easterSunday($year);

        $holidays[$easter->copy()->subDays(3)->toDateString()] = 'Maundy Thursday';
        $holidays[$easter->copy()->subDays(2)->toDateString()] = 'Good Friday';

        ksort($holidays);

        return $holidays;
    }

    /**
     * Easter Sunday for a Gregorian year.
     *
     * Anonymous Gregorian algorithm, rather than easter_days() from ext-calendar,
     * so the office does not depend on an extension being present.
     */
    private function easterSunday(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day);
    }
}
