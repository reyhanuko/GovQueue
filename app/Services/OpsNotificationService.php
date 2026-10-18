<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\OpsNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * In-app messages for the back office, aimed at the role that can act on them.
 *
 * The inbox itself is one screen for clients, staff and administrators, but the
 * contents must not be the same. A client is told about their own appointment; a
 * clerk is told about things that changed on their counter; an administrator is
 * told about things that affect the whole office. Sending everybody the same
 * list would just teach people to ignore it.
 *
 * Deliberately small. Only events that are both rare enough not to become noise
 * and something the recipient can actually do something about are here. Anything
 * continuous - "there are 7 people waiting" - belongs on the live console, not
 * in a list somebody has to read.
 *
 * Every dispatch is wrapped: a notification that cannot be written must never be
 * the reason a booking, a password reset or a registration fails.
 */
class OpsNotificationService
{
    /* ------------------------------------------------------------------ */
    /* Administrator-level */
    /* ------------------------------------------------------------------ */

    /**
     * A new client account, especially one claiming statutory priority.
     *
     * Priority confers nothing until a clerk verifies it at the counter, so the
     * administrator knowing an unverified claim exists is the start of that
     * process. Sent to administrators only: staff meet the client at the window
     * and verify there.
     */
    public function clientRegistered(User $client, bool $claimsPriority = false): void
    {
        $this->toAdmins(new OpsNotification(
            title: $claimsPriority ? 'New account claiming priority' : 'New client account',
            summary: $claimsPriority
                ? $client->name.' registered and claims a priority category. It has no effect until staff verify it at the counter.'
                : $client->name.' registered a client account.',
            url: route('admin.dashboard'),
            level: $claimsPriority ? 'warning' : 'info',
            context: ['email' => $client->email],
        ));
    }

    /**
     * The lobby display has stopped receiving queue updates.
     *
     * Distinct from the banner on the admin dashboard: that one tells you the
     * display is down right now, this one leaves a record so an administrator
     * can see it happened at 14:02 on Tuesday.
     */
    public function realtimeFailed(string $reason): void
    {
        $this->toAdmins(new OpsNotification(
            title: 'Queue display stopped updating',
            summary: 'Live queue updates are failing, so the lobby display may be showing stale numbers. Clients are still being served normally. Reason: '.$reason,
            url: route('admin.dashboard'),
            level: 'critical',
        ));
    }

    /**
     * Live updates are working again.
     *
     * Sent as the counterpart to realtimeFailed, otherwise a display that blipped
     * for thirty seconds leaves an unexplained warning sitting in the inbox.
     */
    public function realtimeRecovered(): void
    {
        $this->toAdmins(new OpsNotification(
            title: 'Queue display is updating again',
            summary: 'Live queue updates are working again. The lobby display should be current.',
            url: route('admin.dashboard'),
            level: 'info',
        ));
    }

    /**
     * A closure was declared over bookings that already exist.
     *
     * The gap this closes: declaring a holiday hides its slots from booking but
     * cancels nobody. The admin dashboard showed a banner and the holidays
     * screen listed the affected clients, but neither told anybody - so clients
     * kept an appointment on a day the office is shut unless a human happened
     * to open one of those two screens. With a 30-day booking horizon, declaring
     * a closure inside that window strands whoever booked it.
     *
     * Administrator-level: deciding who gets rebooked is an administrative call,
     * and it is the one on this screen that carries a client waiting.
     *
     * Not sent when nobody is affected. "Holiday saved, 0 clients affected" is
     * not news, and a notification that fires on every closure trains people to
     * dismiss the ones that matter.
     */
    public function closureClashesWithBookings(string $date, int $affectedCount): void
    {
        if ($affectedCount < 1) {
            return;
        }

        $this->toAdmins(new OpsNotification(
            title: 'Closure clashes with existing bookings',
            summary: sprintf(
                '%s is now a closure, and %d client%s already hold a booking on that date. Their '
                    .'appointments were not cancelled - each one needs rebooking or contacting.',
                $date,
                $affectedCount,
                $affectedCount === 1 ? '' : 's',
            ),
            url: route('admin.holidays.index'),
            level: 'warning',
            context: ['date' => $date, 'affected' => $affectedCount],
        ));
    }

    /**
     * A window has no bookable capacity left on a given date.
     *
     * Once full, the next client to arrive is turned away, and on a walk-in
     * system that is discovered at the counter rather than in advance.
     *
     * Scoped to the date that just filled rather than firing on every slot: read
     * literally, "a window is full" is true every few minutes on a working day,
     * which trains everyone to ignore the inbox. The actionable moment is the
     * transition from one free place to none.
     *
     * Deduplicated per window per date by the caller, so a day that fills,
     * empties and fills again alerts once each time rather than continuously.
     */
    public function windowFilled(string $windowCode, string $serviceName, string $date): void
    {
        $this->toAdmins(new OpsNotification(
            title: 'Window is full',
            summary: sprintf(
                '%s at %s has no capacity left on %s. Later arrivals will be turned away until a '
                    .'slot is freed, cancelled, or another window opens.',
                $serviceName,
                $windowCode,
                Carbon::parse($date)->format('l, j F Y'),
            ),
            url: route('admin.dashboard'),
            level: 'warning',
            context: ['window' => $windowCode, 'service' => $serviceName, 'date' => $date],
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Staff-level */
    /* ------------------------------------------------------------------ */

    /**
     * An administrator moved this member of staff to a different counter.
     *
     * Their console is pinned to their assigned window, so without this they
     * would find out by logging in to a console that is not theirs.
     */
    public function windowAssignmentChanged(User $staff, ?string $previousWindow, ?string $newWindow): void
    {
        $this->toStaff($staff, new OpsNotification(
            title: 'Your window assignment has changed',
            summary: $previousWindow && $newWindow
                ? 'You have been moved from '.$previousWindow.' to '.$newWindow.'. Your console will only open your new window.'
                : ($newWindow
                    ? 'You have been assigned to '.$newWindow.'.'
                    : 'You are no longer assigned to a window. An administrator can assign one.'),
            url: route('staff.dashboard'),
            level: 'warning',
        ));
    }

    /**
     * An administrator reset this member of staff's password.
     *
     * Security-relevant, so it goes to the person whose account was touched
     * rather than only to the administrator who did it.
     *
     * The temporary password is deliberately NOT in the message. Storing it in
     * clear text in a table an administrator can read would turn a convenience
     * into a way to impersonate staff, and the administrator who set the reset
     * has to hand it over in person anyway.
     */
    public function passwordWasReset(User $staff): void
    {
        $this->toStaff($staff, new OpsNotification(
            title: 'Your password was reset',
            summary: 'An administrator reset your password and gave you a new one. Sign in with it and change it straight away.',
            url: route('auth.password.change'),
            level: 'critical',
        ));
    }

    /**
     * This member of staff's account was deactivated.
     *
     * Sent before the change takes effect where possible: the `active` middleware
     * will reject the next request anyway, but the message has to be written while
     * the account can still receive it.
     */
    public function accountDeactivated(User $staff): void
    {
        $this->toStaff($staff, new OpsNotification(
            title: 'Your account has been deactivated',
            summary: 'An administrator deactivated your account, so you will not be able to sign in. Speak to them if this is unexpected.',
            url: route('login'),
            level: 'critical',
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Dispatch */
    /* ------------------------------------------------------------------ */

    /**
     * @param  iterable<int, User>  $recipients
     */
    private function send(iterable $recipients, OpsNotification $notification): void
    {
        foreach ($recipients as $recipient) {
            try {
                Notification::send($recipient, $notification);
            } catch (Throwable $e) {
                // Swallowed on purpose. The caller's job is to register a client
                // or reset a password; refusing to do that because an inbox row
                // could not be written would be a much worse outcome than a
                // missing message.
                Log::warning('GovQueue ops notification failed: '.$e->getMessage(), [
                    'title' => $notification->title,
                    'user_id' => $recipient->id,
                ]);
            }
        }
    }

    private function toAdmins(OpsNotification $notification): void
    {
        $this->send(User::where('role', UserRole::Admin)->where('is_active', true)->get(), $notification);
    }

    private function toStaff(User $staff, OpsNotification $notification): void
    {
        $this->send([$staff], $notification);
    }
}
