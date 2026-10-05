<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\TimeSlot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Answers "which slots can this client still book?" for the booking screens.
 *
 * Availability is derived from the concrete time_slots rows rather than from
 * slot templates, so the number a client sees is the same number the Sprint 3
 * booking transaction locks against.
 */
class SlotAvailabilityService
{
    /**
     * Grouped slots for one service over the booking horizon.
     *
     * @return Collection<int, Collection<int, TimeSlot>> keyed by Y-m-d
     */
    public function availabilityFor(Service $service, ?int $days = null): Collection
    {
        $days = $days ?? config('govqueue.booking_horizon_days');

        // Drop individual slots the client cannot book first, then drop any day
        // left with nothing bookable: offering a day where every slot is full is
        // worse than not offering the day at all.
        return $this->bookableQuery($service, $days)
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn (TimeSlot $slot) => $slot->slot_date->toDateString());
    }

    /**
     * The one definition of "a slot a client can book right now".
     *
     * Three separate answers used to be built by hand here - the grid, the
     * catalogue's open-slot count, and the "next available" hint - and they had
     * drifted apart:
     *
     *  - the hint ignored the booking horizon, so it advertised a date 40 days
     *    out for a 30-day horizon: a date the booking screen then refused to
     *    open, while a free slot three days away went unmentioned.
     *  - the count and the hint ignored declared holidays, so the catalogue
     *    could claim availability on a day the office was shut.
     *
     * They now share this query, so a rule only has to be stated once.
     */
    private function bookableQuery(Service $service, ?int $days = null): Builder
    {
        $days = $days ?? config('govqueue.booking_horizon_days');

        $closed = $this->closedDates($days);

        return TimeSlot::query()
            ->where('service_id', $service->id)
            ->where('is_open', true)
            ->whereColumn('booked_count', '<', 'capacity')
            // The horizon is part of "bookable": a date outside it is not an
            // option the client has, whatever capacity is free.
            ->whereDate('slot_date', '>=', today()->toDateString())
            ->whereDate('slot_date', '<=', today()->addDays($days)->toDateString())
            ->where('start_at', '>=', now())
            // slot_date is a DATE column and $closed holds Y-m-d strings, so this
            // compares like for like and stays index-friendly.
            ->when($closed !== [], fn (Builder $q) => $q->whereNotIn('slot_date', $closed));
    }

    /**
     * Dates inside the horizon when the office is closed.
     *
     * @return array<int, string>
     */
    public function closedDates(?int $days = null): array
    {
        $days = $days ?? config('govqueue.booking_horizon_days');

        return Holiday::query()
            ->whereDate('date', '>=', today()->toDateString())
            ->whereDate('date', '<=', today()->addDays($days)->toDateString())
            ->get()
            ->map(fn (Holiday $h) => $h->date->toDateString())
            ->all();
    }

    /**
     * Earliest bookable slot for a service, used for the "next available" hint.
     *
     * Must agree with availabilityFor() by construction, otherwise the service
     * page promises a date the booking screen will not open.
     */
    public function nextAvailableSlot(Service $service, ?int $days = null): ?TimeSlot
    {
        return $this->bookableQuery($service, $days)
            ->orderBy('start_at')
            ->first();
    }

    /**
     * How many slots a service still has open, for the catalogue listing.
     */
    public function openSlotCount(Service $service, ?int $days = null): int
    {
        return $this->bookableQuery($service, $days)->count();
    }

    /**
     * Whether a client already holds a live booking for this service.
     *
     * Used to steer a client toward rescheduling rather than stacking a second
     * overlapping appointment on the same day.
     */
    public function hasActiveBooking(int $userId, Service $service, ?string $date = null): bool
    {
        return Appointment::query()
            ->where('user_id', $userId)
            ->where('service_id', $service->id)
            ->whereIn('status', ['pending', 'confirmed', 'checked_in'])
            ->when($date, fn ($q) => $q->whereDate('scheduled_at', $date))
            ->exists();
    }

    /**
     * Slots a client has already booked on a given date, so the UI can mark
     * them instead of letting them double-book themselves.
     *
     * @return array<int, int> time_slot ids
     */
    public function bookedSlotIdsFor(int $userId, string $date): array
    {
        return Appointment::query()
            ->where('user_id', $userId)
            ->whereDate('scheduled_at', $date)
            ->whereIn('status', ['pending', 'confirmed', 'checked_in', 'serving'])
            ->pluck('time_slot_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Human label for a grouped availability day.
     */
    public function dayLabel(string $date): string
    {
        $day = Carbon::parse($date);

        return match (true) {
            $day->isToday() => 'Today',
            $day->isTomorrow() => 'Tomorrow',
            default => $day->format('D, M d'),
        };
    }
}
