<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Decides which day the slot picker opens on.
 *
 * Shared by new bookings, by moving an existing appointment, and by the service
 * page's availability preview, so none of the three can drift apart on how they
 * treat the day parameter.
 */
trait ResolvesBookingDay
{
    /**
     * The day to show, which is always exactly one day.
     *
     * A missing or unusable ?date= used to mean "every day", and the picker then
     * rendered the whole booking horizon in one column - around thirty days of
     * slots - so the steps below it sat under a wall and a client could finish
     * without ever seeing that choosing a slot was the first step. That was the
     * reported problem, and it survived the day strip: the strip made changing
     * day easy, but the page still opened on all of them.
     *
     * So there is no longer a "show every day" state. The first day with
     * availability is the default, ?date= moves away from it, and every day is
     * one click away in the strip. That is the only state a client can be in.
     *
     * The parameter is user input, so it is honoured only when it names a day
     * this service can actually be booked on. Anything else would render an
     * empty slot list while looking like it had worked.
     *
     * A preferred day is the day a screen should open on when the client has
     * not asked for one. Moving an appointment has such a day: the day the
     * appointment is on. Opening on the first day with availability instead
     * meant the client was shown a week of times and asked to find their own
     * booking among them - and the marker that says "your current slot" was
     * on a day that was not on the screen. The day the client asked for still
     * wins over the preferred one, because that is what the day strip is for.
     *
     * @param  Collection<string, mixed>  $availability  date => slots
     */
    protected function resolveBookingDay(Request $request, Collection $availability, ?string $preferredDate = null): string
    {
        $first = $availability->keys()->first();

        if ($first === null) {
            return '';
        }

        $requested = $request->query('date');

        if (is_string($requested) && $availability->has($requested)) {
            return $requested;
        }

        return $preferredDate !== null && $availability->has($preferredDate)
            ? $preferredDate
            : $first;
    }
}
