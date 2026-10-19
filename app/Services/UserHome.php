<?php

namespace App\Services;

use App\Models\User;

/**
 * Where a visitor belongs, and what that screen is called.
 *
 * One place, because a link that says one thing and takes you somewhere else
 * is worse than either being wrong on its own. The label is not decoration: an
 * office clerk who is sent to a "dashboard" they do not have is being told the
 * product has a screen it does not.
 *
 * There is no separate dashboard for a clerk or a client, and there does not
 * need to be one. The staff console already shows the day's figures - served,
 * waiting, priority, no-shows, average service - and My Appointments is a
 * client's whole world. A second dashboard per role would be the same numbers
 * on a second screen, with a second set of links to keep in step. What was
 * missing was the *name* of the screen each role actually has.
 */
class UserHome
{
    /**
     * The route a signed-in user keeps their things on.
     *
     * A visitor with no account has no console and no appointments, so the
     * public home page is theirs.
     */
    public function routeFor(?User $user): string
    {
        if (! $user) {
            return 'home';
        }

        return match (true) {
            $user->isAdmin() => 'admin.dashboard',
            $user->isStaff() => 'staff.dashboard',
            default => 'client.appointments.index',
        };
    }

    /**
     * What that screen is called, in the words of the person who will see it.
     *
     * The same names the navigation uses, so a link and the menu it leads to
     * agree.
     */
    public function labelFor(?User $user): string
    {
        if (! $user) {
            return 'the home page';
        }

        return match (true) {
            $user->isAdmin() => 'my dashboard',
            $user->isStaff() => 'my staff console',
            default => 'my appointments',
        };
    }
}
