<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to one or more roles.
 *
 * Usage: ->middleware('role:admin') or ->middleware('role:staff,admin')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        if (! in_array($user->role->value, $roles, true)) {
            return $this->deny($request, $user->role->value, $roles);
        }

        return $next($request);
    }

    /**
     * A signed-in user of the wrong role gets sent back where they came from with
     * an explanation; only a guest, or someone typing a URL for an area that is
     * not theirs, gets the 403 page.
     *
     * Booking is the case that matters: it is a client-only route reached by
     * following a link, so a staff member clicking "Book a slot" used to land on
     * a bare "Access denied" page with no way to recover. The admin area is left
     * as a 403 on purpose - there is no honest "you meant another page" answer
     * for an administrator opening a client-only section, and hiding that would
     * make a permission problem look like a missing page.
     */
    private function deny(Request $request, string $actualRole, array $expected): Response
    {
        $isBookingFlow = $request->is('services/*/book')
            || $request->routeIs('client.booking.*');

        if (! $isBookingFlow) {
            abort(403, 'You do not have permission to access this area.');
        }

        return back()->with(
            'error',
            'Bookings are made from a client account. You are signed in as '.ucfirst($actualRole)
            .', which cannot book appointments.',
        );
    }
}
