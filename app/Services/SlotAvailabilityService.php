<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Holiday;
use App\Models\Service;
use App\Models\TimeSlot;
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

        $slots = TimeSlot::query()
            ->where('service_id', $service->id)
            ->whereDate('slot_date', '>=', today()->toDateString())
            ->whereDate('slot_date', '<=', today()->addDays($days)->toDateString())
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->get();

        $closed = $this->closedDates($days);

        // Drop individual slots the client cannot book first, then drop any day
        // left with nothing bookable: offering a day where every slot is full is
        // worse than not offering the day at all.
        return $slots
            ->filter(fn (TimeSlot $slot) => ! in_array($slot->slot_date->toDateString(), $closed, true))
            ->filter(fn (TimeSlot $slot) => ! $slot->start_at->isPast())
            ->filter(fn (TimeSlot $slot) => $slot->isBookable())
            ->groupBy(fn (TimeSlot $slot) => $slot->slot_date->toDateString());
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
     */
    public function nextAvailableSlot(Service $service): ?TimeSlot
    {
        return TimeSlot::query()
            ->where('service_id', $service->id)
            ->where('is_open', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('start_at', '>=', now())
            ->orderBy('start_at')
            ->first();
    }

    /**
     * How many slots a service still has open, for the catalogue listing.
     */
    public function openSlotCount(Service $service, ?int $days = null): int
    {
        $days = $days ?? config('govqueue.booking_horizon_days');

        return TimeSlot::query()
            ->where('service_id', $service->id)
            ->where('is_open', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('start_at', '>=', now())
            ->whereDate('slot_date', '<=', today()->addDays($days)->toDateString())
            ->count();
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
