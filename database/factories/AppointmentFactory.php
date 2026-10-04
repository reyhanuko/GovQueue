<?php

namespace Database\Factories;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        $slot = TimeSlot::factory();

        return [
            'reference_number' => 'GQ-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'user_id' => User::factory()->client(),
            'service_id' => Service::factory(),
            'time_slot_id' => $slot,
            'service_window_id' => ServiceWindow::factory(),
            'status' => AppointmentStatus::Confirmed,
            'source' => AppointmentSource::Online,
            'scheduled_at' => now()->addDay()->setTime(9, 0),
            'queue_number' => null,
            'queue_date' => today(),
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function checkedIn(): static
    {
        return $this->state(fn () => [
            'status' => AppointmentStatus::CheckedIn,
            'checked_in_at' => now(),
        ]);
    }

    public function serving(): static
    {
        return $this->state(fn () => [
            'status' => AppointmentStatus::Serving,
            'checked_in_at' => now()->subMinutes(15),
            'serving_started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => AppointmentStatus::Completed,
            'checked_in_at' => now()->subMinutes(20),
            'serving_started_at' => now()->subMinutes(15),
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'Client request',
        ]);
    }

    public function walkIn(): static
    {
        return $this->state(fn () => [
            'source' => AppointmentSource::WalkIn,
            'scheduled_at' => null,
        ]);
    }

    /** Priority claim that staff have actually verified. */
    public function verifiedPriority(bool $senior = false, bool $pwd = false, bool $pregnant = false): static
    {
        return $this->state(fn () => [
            'claims_senior_citizen' => $senior,
            'claims_pwd' => $pwd,
            'claims_pregnant' => $pregnant,
            'priority_verified_at' => ($senior || $pwd || $pregnant) ? now() : null,
            'priority_reference' => ($senior || $pwd || $pregnant) ? 'ID-'.Str::upper(Str::random(8)) : null,
        ]);
    }

    /** Priority claimed but not yet verified by staff: still a regular client. */
    public function unverifiedPriority(bool $senior = false, bool $pwd = false, bool $pregnant = false): static
    {
        return $this->state(fn () => [
            'claims_senior_citizen' => $senior,
            'claims_pwd' => $pwd,
            'claims_pregnant' => $pregnant,
            'priority_verified_at' => null,
        ]);
    }

    public function forQueueDate(Carbon|string $date): static
    {
        return $this->state(fn () => [
            'queue_date' => Carbon::parse($date)->toDateString(),
        ]);
    }
}
