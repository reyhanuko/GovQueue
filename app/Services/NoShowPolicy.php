<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\NoShowStrike;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The no-show policy (decision D6).
 *
 * A resident who does not turn up, does not cancel, and is closed out by the
 * hourly command has recorded an unexcused no-show. Three of those block new
 * bookings for a fortnight.
 *
 * Two rules decide what counts, and both are here rather than in the command
 * that applies them, so that the rule is the same wherever a no-show is
 * recorded:
 *
 *  - **A no-show is unexcused when the client never checked in.** A client who
 *    came to the office and was never called has been let down by the office's
 *    own queue; a client who never came and never said anything has not.
 *  - **The count starts again after a block.** The strikes that produced a
 *    block are older than the block itself, so they stop counting when it
 *    ends. A resident who serves their fortnight is not blocked again by the
 *    same three absences.
 *
 * The block is on *booking*. A walk-in is not a booking, so a blocked resident
 * can still be served at the counter - which is what makes it a block rather
 * than a ban.
 */
class NoShowPolicy
{
    /**
     * How many unexcused no-shows are needed before a client is blocked.
     */
    public function threshold(): int
    {
        return max(1, (int) config('govqueue.no_show_policy.strike_threshold', 3));
    }

    /**
     * How long the block lasts, in days.
     */
    public function blockDays(): int
    {
        return max(0, (int) config('govqueue.no_show_policy.block_days', 14));
    }

    /**
     * Record an unexcused no-show against a client, and block them if it was
     * the one that reached the threshold.
     *
     * @return bool whether this strike is the one that triggered the block
     */
    public function recordStrike(Appointment $appointment, ?string $note = null): bool
    {
        $client = $appointment->user;

        if (! $client) {
            return false;
        }

        $blocked = false;

        DB::transaction(function () use ($appointment, $client, $note, &$blocked) {
            NoShowStrike::create([
                'user_id' => $client->id,
                'appointment_id' => $appointment->id,
                'note' => $note,
                // Stamped here rather than by the model: the table has no
                // updated_at, and the count of strikes since the last block
                // is read from this column.
                'created_at' => now(),
            ]);

            $client->refresh();

            if ($this->strikeCount($client) >= $this->threshold()) {
                $client->forceFill([
                    'booking_blocked_until' => now()->addDays($this->blockDays()),
                ])->save();

                $blocked = true;
            }
        });

        return $blocked;
    }

    /**
     * The unexcused no-shows that count against this client right now.
     *
     * Strikes older than the block that is currently in force have already
     * been served, so they do not count.
     */
    public function strikeCount(User $client): int
    {
        $since = $client->booking_blocked_until?->copy()->addSeconds(1);

        return NoShowStrike::query()
            ->where('user_id', $client->id)
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->count();
    }

    /**
     * Is this client currently blocked from making new bookings?
     */
    public function isBlocked(User $client): bool
    {
        return $client->booking_blocked_until !== null
            && $client->booking_blocked_until->isFuture();
    }

    /**
     * When the block ends, if it is in force.
     */
    public function blockedUntil(User $client): ?Carbon
    {
        if (! $this->isBlocked($client)) {
            return null;
        }

        return $client->booking_blocked_until;
    }

    /**
     * The sentence the client is shown.
     *
     * In the client's own words, and specific about when it ends: a resident
     * who is told only that they are blocked cannot do anything about it.
     */
    public function blockMessage(User $client): string
    {
        $until = $this->blockedUntil($client);

        if (! $until) {
            return '';
        }

        return 'You cannot make a new booking until '.$until->format('M d, Y').'. '
            .'Three appointments were closed out without you attending, so new bookings '
            .'are paused for '.$this->blockDays().' days. Please contact the municipal '
            .'office if you think this is wrong.';
    }
}
