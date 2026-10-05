<?php

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
*/

/*
 * Only private/presence channels are registered here, because that is the only
 * kind Laravel Echo asks this endpoint to authorise - Echo.channel() for a public
 * channel connects with the app key alone and never posts here.
 *
 * Registering a public channel callback was actively harmful: Laravel's
 * validAuthenticationResponse() calls method_exists() on the authenticated user
 * regardless of channel type, so authorising a public channel for a guest threw
 * a TypeError and returned 500.
 *
 * The lobby display therefore subscribes to `queue.{WINDOW}` as a public Echo
 * channel and deliberately never calls Echo.private() for it. Its privacy comes
 * from the event payload, not from channel authorisation: QueueUpdated carries
 * queue numbers and window names only.
 */

/*
 * A client watching their own ticket. Must never be readable by anyone else.
 *
 * Laravel always passes the authenticated user as the FIRST argument, and the
 * wildcard placeholders positionally after it, hence ($user, $appointmentId).
 */
Broadcast::channel('appointment.{appointmentId}', function (?User $user, $appointmentId = null) {
    if (! $user || ! is_numeric($appointmentId)) {
        return false;
    }

    return Appointment::where('id', (int) $appointmentId)
        ->where('user_id', $user->id)
        ->exists();
});

/*
 * A client watching every ticket they hold.
 */
Broadcast::channel('client.{userId}', function (?User $user, $userId = null) {
    return $user !== null
        && is_numeric($userId)
        && (int) $user->id === (int) $userId;
});
