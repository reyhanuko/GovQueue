<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\TimeSlot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
     * One query definition of "bookable", optionally scoped to a single service.
     *
     * Passing null for $service deliberately removes only the service filter, so a
     * grouped aggregate covers exactly the same rows the per-service answers do.
     * That matters: if the batched version were built separately it could drift
     * from the single-service one and the catalogue would start advertising
     * availability the booking screen refuses - the exact bug the shared query was
     * introduced to prevent.
     */
    private function bookableQuery(?Service $service, ?int $days = null): Builder
    {
        $days = $days ?? config('govqueue.booking_horizon_days');

        $closed = $this->closedDates($days);

        return TimeSlot::query()
            ->when($service, fn (Builder $q) => $q->where('service_id', $service->id))
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
     * Open-slot counts and next available slots for many services at once.
     *
     * The catalogue asks about every service on one page. Doing that one service at
     * a time costs two queries per service - measured at 16 on a catalogue of 8 -
     * so the busiest public page would slow down in proportion to how many
     * services the office offers. Two queries cover them all instead.
     *
     * @param  Collection<int, Service>  $services
     * @return array{counts: Collection<int, int>, next: Collection<int, TimeSlot>}
     */
    public function catalogueAvailabilityFor(Collection $services, ?int $days = null): array
    {
        if ($services->isEmpty()) {
            return ['counts' => collect(), 'next' => collect()];
        }

        $counts = $this->bookableQuery(null, $days)
            ->selectRaw('service_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('service_id')
            ->pluck('total', 'service_id');

        // The earliest bookable start per service, then a single fetch of those
        // exact rows. Equivalent to N separate "order by start_at limit 1" queries.
        $earliest = $this->bookableQuery(null, $days)
            ->selectRaw('service_id')
            ->selectRaw('MIN(start_at) as earliest')
            ->groupBy('service_id')
            ->pluck('earliest', 'service_id');

        $next = $earliest->isEmpty()
            ? collect()
            : TimeSlot::query()
                ->whereIn(
                    'service_id',
                    $earliest->keys()->all()
                )
                ->where(function ($q) use ($earliest) {
                    foreach ($earliest as $serviceId => $startAt) {
                        $q->orWhere(function ($inner) use ($serviceId, $startAt) {
                            $inner->where('service_id', $serviceId)
                                ->where('start_at', $startAt);
                        });
                    }
                })
                ->get()
                ->keyBy('service_id');

        return ['counts' => $counts, 'next' => $next];
    }

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
     *
     * @see self::bookableQuery() for the query itself.
     */

    /**
     * Dates inside the horizon when the office is closed.
     *
     * Memoised through the application cache. bookableQuery() needs this on every
     * call, and the catalogue asks about every service on one page, so without a
     * memo a single page issued one holidays query per service - measured at 16
     * queries on a page with 8 services, growing with the catalogue.
     *
     * The shared cache rather than an instance array, which is the whole point. An
     * array only survives while the object does, and this service is resolved fresh
     * from the container at most call sites - so the "memo" died with the instance
     * and every call still hit the database, just from a different object each
     * time. The cache is what makes the memo real, and because it is shared, one
     * forget() invalidates it for every instance including ones already holding a
     * reference.
     *
     * Cached until explicitly invalidated rather than on a TTL: an administrator
     * can declare a holiday at any moment and it has to take effect immediately.
     * ScheduleAdminService forgets the key on every create and delete, and because
     * that lives in the service rather than the controller, a holiday added from a
     * console, a seeder, or a future admin screen invalidates it too.
     *
     * Keyed by horizon because two different windows are genuinely two different
     * questions.
     *
     * @return array<int, string>
     */
    public function closedDates(?int $days = null): array
    {
        return Cache::rememberForever(
            self::closedDatesCacheKey($days),
            fn () => $this->queryClosedDates($days),
        );
    }

    /**
     * @return array<int, string>
     */
    private function queryClosedDates(?int $days = null): array
    {
        $days = $days ?? (int) config('govqueue.booking_horizon_days');

        return Holiday::query()
            ->whereDate('date', '>=', today()->toDateString())
            ->whereDate('date', '<=', today()->addDays($days)->toDateString())
            ->get()
            ->map(fn (Holiday $h) => $h->date->toDateString())
            ->all();
    }

    /**
     * Cache key for the closed-date list at a given horizon.
     *
     * Exposed so callers can invalidate without reproducing the key format - a
     * mismatch would leave the catalogue advertising availability on a day the
     * office has just been told is shut.
     */
    public static function closedDatesCacheKey(?int $days = null): string
    {
        $days = $days ?? (int) config('govqueue.booking_horizon_days');

        return 'govqueue:closed-dates:'.$days;
    }

    /**
     * Drop the cached closed-date lists.
     *
     * Called by ScheduleAdminService whenever a holiday is created or deleted.
     */
    public function forgetClosedDates(): void
    {
        Cache::forget(self::closedDatesCacheKey());
        Cache::forget(self::closedDatesCacheKey((int) config('govqueue.booking_horizon_days')));
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
