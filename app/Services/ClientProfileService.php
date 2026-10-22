<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PriorityType;
use App\Models\User;

/**
 * Keeps a client's statutory priority data coherent with their profile.
 *
 * Two rules are enforced here rather than in controllers so they hold no
 * matter which screen submits the data:
 *
 *  1. RA 9994 senior status is derived from the date of birth, not ticked by
 *     the client, so it cannot drift from the profile.
 *  2. A date of birth under the threshold clears the senior claim entirely.
 *
 * Nothing is persisted on the user record: the entitlement is read from the
 * date of birth on file every time it is asked for, so there is no stored copy
 * that can go stale.
 */
class ClientProfileService
{
    /**
     * Does this client qualify for RA 9994 senior-citizen priority?
     *
     * Read from the date of birth, which is the only evidence the law asks
     * for. A client cannot claim it, and it cannot be granted by a staff
     * member either: the birthdate either proves it or it does not.
     */
    public function qualifiesAsSeniorCitizen(User $user): bool
    {
        return $user->meetsSeniorCitizenAge();
    }

    /**
     * Apply the corrected date of birth to the client's open appointments.
     *
     * A senior citizen claim is not an independent fact: it is the date of
     * birth on file, read at the moment the booking was made. So when the
     * date of birth is corrected - because it was typed wrong, or because the
     * client was born on a different day than they said - the claim on the
     * bookings they still hold has to follow.
     *
     * Without this, a client whose date of birth was wrong kept a senior
     * claim the system itself no longer supports, and a client whose date of
     * birth was corrected upward lost the priority they had every right to.
     *
     * Only the senior claim moves. A PWD or pregnancy claim is made on a
     * document and belongs to that booking alone.
     *
     * A claim staff have already verified is left alone: that is their
     * decision, made against the document the client presented, and the
     * record of it does not change because a profile field was edited. The
     * console shows the date of birth next to a verified claim, so a clerk
     * can see a date that does not add up and refuse it.
     *
     * @return int the number of appointments whose claim changed
     */
    public function reapplySeniorClaimToOpenAppointments(User $user): int
    {
        $qualifies = $this->qualifiesAsSeniorCitizen($user);

        $changed = 0;

        $appointments = $user->appointments()
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
            ])
            ->whereNull('priority_verified_at')
            ->get();

        foreach ($appointments as $appointment) {
            if ((bool) $appointment->claims_senior_citizen === $qualifies) {
                continue;
            }

            $appointment->forceFill(['claims_senior_citizen' => $qualifies])->save();
            $changed++;
        }

        return $changed;
    }

    /**
     * Priority categories the client is currently entitled to claim, derived
     * from their own profile.
     *
     * @return array<int, PriorityType>
     */
    public function claimableCategories(User $user): array
    {
        $types = [];

        if ($this->qualifiesAsSeniorCitizen($user)) {
            $types[] = PriorityType::SeniorCitizen;
        }

        return $types;
    }

    /**
     * Explains to the client why a category is or is not available to them.
     *
     * @return array<string, string>
     */
    public function explainEligibility(User $user): array
    {
        $threshold = (int) config('govqueue.senior_citizen_age');
        $age = $user->ageInYears();

        return [
            PriorityType::SeniorCitizen->value => $age === null
                ? 'Add your date of birth so we can identify RA 9994 priority.'
                : ($age >= $threshold
                    ? "You qualify: {$age} years old (RA 9994 threshold is {$threshold})."
                    : "You do not qualify yet: you are {$age}. RA 9994 priority starts at {$threshold}."),
            PriorityType::Pwd->value => 'Claimable per transaction. Staff will verify your reference at the counter (RA 10754).',
            PriorityType::Pregnant->value => 'Claimable per transaction. Please bring a supporting reference to the counter.',
        ];
    }
}
