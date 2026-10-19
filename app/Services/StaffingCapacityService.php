<?php

namespace App\Services;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\User;

/**
 * How many clients a window can actually serve in one slot.
 *
 * "Places per slot" is a number an administrator types, and until now nothing
 * related it to whether the window has anyone to serve those places. A window
 * with one clerk and a 30-minute slot can serve roughly two clients of a
 * 20-minute service, so a template offering four places is promising a wait the
 * counter cannot keep.
 *
 * This service computes that comparison so the admin screens can warn about it.
 *
 * The formula:
 *
 *     placesServable = ceil( (staff_on_window x slot_minutes) / duration_minutes )
 *
 * staff x slot_minutes is the labour minutes genuinely available in that slot,
 * which is why the figure moves when a second clerk is added - which is the
 * whole point of the "add a place because a second clerk opened" case. Dividing
 * by the service's own duration gives the clients that labour can absorb.
 *
 * ceil rather than floor, because a slot that can absorb 1.5 clients still
 * books two; floor would warn on a configuration that is actually fine.
 *
 * THE PER-WINDOW SHARED BUDGET (decision D1)
 *
 * The check above is per service, and on its own it is misleading. Window 1 has
 * one clerk and three services; each template offers four places. Every service's
 * own warning is satisfied - four places, and one clerk can serve about six in a
 * 30-minute slot - so every warning stays quiet, while the window as a whole is
 * offering twelve clients against the roughly three that clerk can actually see.
 *
 * Lowering one service to three makes its warning appear, and the administrator
 * fixes it, and the window is still overcommitted by the other two.
 *
 * So the real question is not "can this service be served" but "can this window,
 * across all its services, serve everything it is offering at this time". That is
 * what aggregateWarning() answers, by summing the places offered across every
 * service sharing the window's hours and comparing that against one budget.
 *
 * ROUNDING, AND WHY THE TWO FIGURES DIFFER
 *
 * placesServable() ceils; the shared budget floors.
 *
 * They answer different questions. "Can this one slot serve this one service's
 * clients?" - if the answer is 1.5, the slot can still book two, so it must not
 * be treated as one. "How many clients may this window commit to in this slot?"
 * - if the answer is 1.5, committing two overruns the labour minutes and the
 * second client waits, so it must be treated as one.
 *
 * A per-service cap errs on the side of offering a place; a shared budget errs
 * on the side of not overcommitting. Both errings are deliberate, and using ceil
 * for the budget would let a window promise a place it has no minutes for.
 */
class StaffingCapacityService
{
    /**
     * How many clients this window could serve in a slot of this length for a
     * service taking this long.
     *
     * A window with no active staff assigned returns 0 rather than null: it is
     * genuinely unable to serve anybody, and warning about it is correct. A
     * caller that wants to stay quiet about unstaffed windows should check
     * staffCount() first.
     */
    public function placesServable(int $staffCount, int $slotMinutes, int $durationMinutes): int
    {
        if ($staffCount < 1 || $slotMinutes < 1) {
            return 0;
        }

        // A service with no duration recorded cannot be compared; treat it as a
        // single-minute transaction so the caller gets a number, and the warning
        // reads as "this service has no duration set" via the caller.
        $duration = max(1, $durationMinutes);

        return (int) ceil(($staffCount * $slotMinutes) / $duration);
    }

    /** Active staff assigned to this window. */
    public function staffCount(ServiceWindow $window): int
    {
        return User::where('service_window_id', $window->id)
            ->where('is_active', true)
            ->whereIn('role', ['staff', 'admin'])
            ->count();
    }

    /* ------------------------------------------------------------------ */
    /* Decision D1: the per-window shared budget */
    /* ------------------------------------------------------------------ */

    /**
     * How many clients this window can genuinely serve in one slot, across all
     * its services together.
     *
     * staff x floor(slot_minutes / average_service_minutes)
     *
     * floor, not ceil, and the difference is load-bearing - see the class
     * docblock. A budget that promised 2 against 1.5 would commit the window to a
     * client it has no minutes for.
     *
     * The average is taken across the window's services rather than read from
     * windows.avg_service_minutes, because that column is a second, separately
     * editable copy of a number every service already carries, and the two drift
     * apart (finding F-06: a window edited to 5 minutes while its services said
     * 10 to 15). Service duration is the source of truth (decision D3).
     *
     * A window with no services, or with none carrying a duration, cannot be
     * measured and yields null rather than a number - "we cannot tell" is the
     * honest answer, and a caller that treats null as zero would warn about a
     * window nobody has configured yet.
     */
    public function sharedBudget(ServiceWindow $window, int $slotMinutes, ?float $averageServiceMinutes = null): ?int
    {
        $staff = $this->staffCount($window);

        if ($staff < 1 || $slotMinutes < 1) {
            return 0;
        }

        $average ??= $this->averageServiceMinutes($window);

        if ($average === null || $average < 1) {
            return null;
        }

        return (int) floor(($staff * $slotMinutes) / $average);
    }

    /**
     * Mean service duration across a window's services, or null if unknowable.
     */
    public function averageServiceMinutes(ServiceWindow $window): ?float
    {
        $durations = Service::where('service_window_id', $window->id)
            ->where('is_active', true)
            ->where('duration_minutes', '>', 0)
            ->pluck('duration_minutes');

        if ($durations->isEmpty()) {
            return null;
        }

        return round((float) $durations->avg(), 2);
    }

    /**
     * Places offered across every service in a window for a given slot length.
     *
     * The "demand" side of the comparison, summed from the services' own
     * capacity rather than from the generated slot rows, because the templates
     * are where an administrator sets capacity and the slots are only their
     * expansion. Summing slots would count the same template once per service
     * that shares its times, which is the same over-counting the warning exists
     * to catch - just with the arithmetic moved.
     *
     * @param  array<int,int>  $placesByServiceId  capacity per service id
     */
    public function placesOffered(array $placesByServiceId): int
    {
        return (int) array_sum($placesByServiceId);
    }

    /**
     * The window-level warning, or null when the window is not overcommitted.
     *
     * Answers the question the per-service warning cannot: whether the window, as
     * a whole, is offering more clients at this time than its clerks can see.
     */
    public function aggregateWarning(
        ServiceWindow $window,
        int $slotMinutes,
        int $placesOffered,
    ): ?string {
        $staff = $this->staffCount($window);

        if ($staff < 1) {
            return $window->name.' has no active staff assigned, so nobody can be served there. '
                .'Across its services this window is offering '.$placesOffered.' places per slot.';
        }

        $budget = $this->sharedBudget($window, $slotMinutes);

        // null means the window's services carry no duration to measure against.
        // A caller that read that as zero would cry oversubscription on a window
        // that simply has not been configured yet.
        if ($budget === null) {
            return null;
        }

        if ($placesOffered <= $budget) {
            return null;
        }

        $average = $this->averageServiceMinutes($window);
        $serviceCount = Service::where('service_window_id', $window->id)->where('is_active', true)->count();

        return $window->name.' has '.$staff.' active '.($staff === 1 ? 'staff member' : 'staff members').'. '
            .'A '.$slotMinutes.'-minute slot holds about '.number_format($average, 1).' minutes of service on average '
            .'across its '.$serviceCount.' '.($serviceCount === 1 ? 'service' : 'services').', so the window can serve '
            .'about '.$budget.' '.($budget === 1 ? 'client' : 'clients').' per slot in total. '
            .'Its services currently offer '.$placesOffered.' places between them. '
            .'Every service looks reasonable on its own, and together they promise a wait the counter cannot keep.';
    }

    /**
     * Is this capacity over what the window can serve?
     */
    public function isOverCapacity(
        int $staffCount,
        int $slotMinutes,
        int $durationMinutes,
        int $capacity,
    ): bool {
        return $capacity > $this->placesServable($staffCount, $slotMinutes, $durationMinutes);
    }

    /**
     * The warning, or null when the configuration is fine.
     *
     * Returned as a sentence rather than raw numbers so the admin screens do not
     * each have to word it, and so the wording stays consistent between the
     * template form and the daily slots screen.
     */
    public function warning(
        ServiceWindow $window,
        int $slotMinutes,
        int $durationMinutes,
        int $capacity,
        string $serviceLabel,
    ): ?string {
        $staff = $this->staffCount($window);

        if ($staff < 1) {
            return $window->name.' has no active staff assigned, so nobody can be served there. '
                .'This template offers '.$capacity.' places per slot.';
        }

        $servable = $this->placesServable($staff, $slotMinutes, $durationMinutes);

        if ($capacity <= $servable) {
            return null;
        }

        return $window->name.' has '.$staff.' active '.($staff === 1 ? 'staff member' : 'staff members').'. '
            .'A '.$slotMinutes.'-minute slot can serve about '.$servable.' '
            .($servable === 1 ? 'client' : 'clients').' of '.$serviceLabel.' at '.$durationMinutes.' minutes each, '
            .'but this template offers '.$capacity.' places per slot. Clients who book beyond what the '
            .'counter can serve will wait longer than the estimates given to them.';
    }
}
