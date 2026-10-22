<?php

namespace App\Http\Controllers\Client\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Decides which day the slot picker opens on.
 *
 * Shared by new bookings and by moving an existing appointment, so the two
 * screens cannot drift apart on how they treat the day parameter.
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
     * @param  Collection<string, mixed>  $availability  date => slots
     */
    protected function resolveBookingDay(Request $request, Collection $availability): string
    {
        $first = $availability->keys()->first();

        if ($first === null) {
            return '';
        }

        $requested = $request->query('date');

        return is_string($requested) && $availability->has($requested)
            ? $requested
            : $first;
    }
}
