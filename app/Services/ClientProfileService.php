<?php

namespace App\Services;

use App\Enums\PriorityType;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Keeps a client's statutory priority data coherent with their profile.
 *
 * Two rules are enforced here rather than in controllers so they hold no
 * matter which screen submits the data:
 *
 *  1. RA 9994 senior status is derived from the date of birth, not ticked by
 *     the client, so it cannot drift from the profile.
 *  2. A date of birth under the threshold clears the senior claim entirely.
 */
class ClientProfileService
{
    /**
     * Recompute and persist the senior-citizen claim from the birthdate.
     *
     * @return bool whether the client qualifies
     */
    public function syncSeniorCitizenClaim(User $user): bool
    {
        $threshold = (int) config('govqueue.senior_citizen_age');
        $qualifies = (int) Carbon::parse($user->birthdate)->age >= $threshold;

        // is_pwd / is_pregnant are not stored on the user record by design:
        // pregnancy is time-bound, and the project never verifies PWD status
        // itself, so both are claimed per-appointment in Sprint 3.
        if ($user->claims_senior_citizen !== $qualifies) {
            $user->forceFill(['claims_senior_citizen' => $qualifies])->save();
        }

        return $qualifies;
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

        if ($this->syncSeniorCitizenClaim($user)) {
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
