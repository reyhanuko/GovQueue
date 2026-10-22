<?php

namespace Tests\Unit;

use App\Enums\PriorityType;
use App\Models\User;
use App\Services\ClientProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClientProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ClientProfileService;
    }

    public function test_it_grants_senior_status_at_the_ra_9994_threshold(): void
    {
        $threshold = (int) config('govqueue.senior_citizen_age');

        // Born exactly on the day they turn 60.
        $user = User::factory()->create([
            'birthdate' => now()->subYears($threshold)->subDay()->toDateString(),
        ]);

        $this->assertTrue($this->service->qualifiesAsSeniorCitizen($user));
        $this->assertTrue($user->fresh()->claims_senior_citizen);
    }

    public function test_it_does_not_grant_senior_status_one_day_short_of_the_threshold(): void
    {
        $threshold = (int) config('govqueue.senior_citizen_age');

        $user = User::factory()->create([
            'birthdate' => now()->subYears($threshold)->addDay()->toDateString(),
        ]);

        $this->assertFalse($this->service->qualifiesAsSeniorCitizen($user));
        $this->assertFalse($user->fresh()->claims_senior_citizen,
            'A birthdate under the threshold must not carry senior priority.');
    }

    public function test_it_cannot_be_played_by_setting_the_flag_directly(): void
    {
        // The entitlement is read from the date of birth, so a client who
        // somehow holds a senior flag without qualifying is still not senior.
        $user = User::factory()->create([
            'birthdate' => '1990-01-01',
        ]);

        $user->setAttribute('claims_senior_citizen', true);

        $this->assertSame([], $this->service->claimableCategories($user));
        $this->assertFalse($user->fresh()->claims_senior_citizen);
    }

    public function test_claimable_categories_contains_only_senior_status(): void
    {
        $user = User::factory()->create([
            'birthdate' => '1950-01-01',
        ]);

        $categories = $this->service->claimableCategories($user);

        $this->assertSame([PriorityType::SeniorCitizen], $categories);
    }

    public function test_it_explains_why_a_client_does_not_qualify(): void
    {
        $threshold = (int) config('govqueue.senior_citizen_age');
        $user = User::factory()->create(['birthdate' => '1990-01-01']);

        $explanation = $this->service->explainEligibility($user)[PriorityType::SeniorCitizen->value];

        $this->assertStringContainsString('do not qualify', $explanation);
        $this->assertStringContainsString((string) $threshold, $explanation);
    }

    public function test_it_explains_that_a_qualifying_client_is_eligible(): void
    {
        $user = User::factory()->create(['birthdate' => '1950-01-01']);

        $explanation = $this->service->explainEligibility($user)[PriorityType::SeniorCitizen->value];

        $this->assertStringContainsString('You qualify', $explanation);
    }

    public function test_it_explains_pwd_and_pregnant_are_claimed_per_transaction(): void
    {
        $user = User::factory()->create(['birthdate' => '1990-01-01']);

        $explanation = $this->service->explainEligibility($user);

        $this->assertStringContainsString('per transaction', $explanation[PriorityType::Pwd->value]);
        $this->assertStringContainsString('per transaction', $explanation[PriorityType::Pregnant->value]);
        $this->assertStringContainsString('RA 10754', $explanation[PriorityType::Pwd->value]);
    }

    public function test_missing_birthdate_produces_a_prompt_rather_than_a_crash(): void
    {
        $user = User::factory()->create(['birthdate' => null]);

        $explanation = $this->service->explainEligibility($user)[PriorityType::SeniorCitizen->value];

        $this->assertStringContainsString('date of birth', $explanation);
    }
}
