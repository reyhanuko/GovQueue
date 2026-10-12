<?php

namespace App\Services;

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
 * KNOWN LIMITATION: this is a per-service check. It does not model contention
 * between services sharing a window's hours - two services at four places each,
 * both booked into overlapping slots, overcommits the same clerk, and neither
 * warning fires. Catching that needs the expanded slot schedule to know which
 * services actually overlap, which is a larger piece of work. Flagged rather
 * than hidden.
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

    /**
     * Is this capacity over what the window can serve?
     *
     * @return bool
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