<?php

namespace Database\Seeders;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\SlotGeneratorService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Produces a believable day of queue traffic so the staff console, public
 * display, and analytics dashboard all have something real to show on a fresh
 * `php artisan migrate:fresh --seed`.
 */
class DemoActivitySeeder extends Seeder
{
    public function run(): void
    {
        $generator = app(SlotGeneratorService::class);

        // Four weeks back (for analytics) through three weeks ahead (bookable).
        $generator->generate(today()->subDays(28), today()->addDays(21));

        $clients = User::where('role', UserRole::Client)->get();
        $staff = User::where('role', UserRole::Staff)->get();
        $services = Service::active()->get();

        if ($clients->isEmpty() || $services->isEmpty()) {
            return;
        }

        $this->seedPastDays($clients, $staff, $services);
        $this->seedToday($clients, $staff, $services);
        $this->seedUpcoming($clients, $services);
    }

    /** Completed and no-show traffic across the past four weeks. */
    private function seedPastDays($clients, $staff, $services): void
    {
        $sequence = 0;

        for ($daysAgo = 28; $daysAgo >= 1; $daysAgo--) {
            $date = today()->subDays($daysAgo);

            if ($date->isSunday()) {
                continue;
            }

            // Weekday mornings are busier, which gives the peak-hour report
            // something recognisable to show.
            $volume = $date->isWeekday() ? random_int(6, 14) : random_int(2, 5);

            foreach (range(1, $volume) as $i) {
                $service = $services->random();
                $slot = TimeSlot::where('service_id', $service->id)
                    ->forDate($date)
                    ->inRandomOrder()
                    ->first();

                if (! $slot) {
                    continue;
                }

                $start = Carbon::parse($slot->start_at);
                $checkedIn = $start->copy()->addMinutes(random_int(-10, 12));
                $serving = $checkedIn->copy()->addMinutes(random_int(3, 25));
                $completed = $serving->copy()->addMinutes(random_int(5, 25));

                $isNoShow = random_int(1, 10) === 1;

                $client = $this->pickClient($clients);
                $status = $isNoShow
                    ? AppointmentStatus::NoShow
                    : AppointmentStatus::Completed;

                $priority = $this->maybePriority($client, $date);

                $appointment = Appointment::create([
                    'reference_number' => $this->reference($date, $sequence++),
                    'user_id' => $client->id,
                    'service_id' => $service->id,
                    'time_slot_id' => $slot->id,
                    'service_window_id' => $service->service_window_id,
                    'status' => $status,
                    'source' => AppointmentSource::Online,
                    'scheduled_at' => $start,
                    'queue_number' => 'A-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'queue_date' => $date->toDateString(),
                    ...$priority,
                    'confirmed_at' => $start->copy()->subDays(3),
                    'checked_in_at' => $isNoShow ? null : $checkedIn,
                    'serving_started_at' => $isNoShow ? null : $serving,
                    'completed_at' => $isNoShow ? null : $completed,
                ]);

                $slot->increment('booked_count');

                $this->writeHistory($appointment, $isNoShow);
            }
        }
    }

    /**
     * The live queue for the current operating day.
     *
     * The office does not open on Sundays or the seeded holidays, so on those
     * days there is legitimately nothing to show. Rather than leave the staff
     * console and public display empty on first run, the demo falls back to the
     * next day that actually has slots.
     */
    private function seedToday($clients, $staff, $services): void
    {
        $today = today();
        $sequence = 0;

        $service = $services->first();

        $slots = TimeSlot::where('service_id', $service->id)
            ->forDate($today)
            ->orderBy('start_time')
            ->get();

        if ($slots->isEmpty()) {
            $next = TimeSlot::where('service_id', $service->id)
                ->where('slot_date', '>', $today->toDateString())
                ->orderBy('slot_date')
                ->orderBy('start_time')
                ->get()
                ->groupBy('slot_date')
                ->keys()
                ->first();

            if ($next === null) {
                return;
            }

            $slots = TimeSlot::where('service_id', $service->id)
                ->forDate($next)
                ->orderBy('start_time')
                ->get();
        }

        $servingSlot = $slots->first();

        if (! $servingSlot) {
            return;
        }

        $serving = Appointment::create([
            'reference_number' => $this->reference($today, $sequence),
            'user_id' => $clients->random()->id,
            'service_id' => $service->id,
            'time_slot_id' => $servingSlot->id,
            'service_window_id' => $service->service_window_id,
            'status' => AppointmentStatus::Serving,
            'source' => AppointmentSource::Online,
            'scheduled_at' => Carbon::parse($servingSlot->start_at),
            'queue_number' => 'A-001',
            'queue_date' => $today->toDateString(),
            'confirmed_at' => now()->subHours(3),
            'checked_in_at' => now()->subMinutes(12),
            'called_at' => now()->subMinutes(6),
            'serving_started_at' => now()->subMinutes(6),
        ]);
        $this->writeHistory($serving);

        // A verified PWD client and a verified senior citizen, both waiting, so
        // the priority reorder is visible the moment the staff console opens.
        $pwd = $clients->firstWhere('email', 'pwd@govqueue.test')
            ?? $clients->firstWhere('email', 'senior@govqueue.test');
        $senior = $clients->firstWhere('email', 'senior@govqueue.test');

        if ($pwd && $senior && $pwd->id !== $senior->id) {
            foreach ([
                [$senior, 'A-002', ['claims_senior_citizen' => true]],
                [$pwd, 'A-003', ['claims_pwd' => true]],
            ] as [$client, $queueNumber, $claims]) {
                $appointment = Appointment::create([
                    'reference_number' => $this->reference($today, ++$sequence),
                    'user_id' => $client->id,
                    'service_id' => $service->id,
                    'time_slot_id' => $servingSlot->id,
                    'service_window_id' => $service->service_window_id,
                    'status' => AppointmentStatus::CheckedIn,
                    'source' => AppointmentSource::Online,
                    'scheduled_at' => Carbon::parse($servingSlot->start_at),
                    'queue_number' => $queueNumber,
                    'queue_date' => $today->toDateString(),
                    ...$claims,
                    'priority_reference' => 'PWD-'.Str::upper(Str::random(6)),
                    'priority_verified_at' => now()->subMinutes(5),
                    'confirmed_at' => now()->subHours(3),
                    'checked_in_at' => now()->subMinutes(random_int(2, 9)),
                ]);

                $this->writeHistory($appointment);
            }
        }

        // Ordinary walk-ins still waiting.
        foreach (range(1, random_int(3, 5)) as $i) {
            $appointment = Appointment::create([
                'reference_number' => $this->reference($today, ++$sequence),
                'user_id' => $clients->random()->id,
                'service_id' => $service->id,
                'time_slot_id' => $servingSlot->id,
                'service_window_id' => $service->service_window_id,
                'status' => AppointmentStatus::CheckedIn,
                'source' => AppointmentSource::WalkIn,
                'scheduled_at' => null,
                'queue_number' => 'W-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'queue_date' => $today->toDateString(),
                'confirmed_at' => now()->subHour(),
                'checked_in_at' => now()->subMinutes(random_int(1, 8)),
            ]);

            $this->writeHistory($appointment);
        }
    }

    /** Confirmed future bookings with a mix of priority claims. */
    private function seedUpcoming($clients, $services): void
    {
        $sequence = 0;

        // Always reach forward far enough to clear any seeded holiday.
        foreach (range(1, 8) as $i) {
            $service = $services->random();

            $slot = TimeSlot::where('service_id', $service->id)
                ->whereDate('slot_date', '>', today()->toDateString())
                ->where('booked_count', '<', 'capacity')
                ->orderBy('slot_date')
                ->orderByRaw('RAND()')
                ->first();

            if (! $slot) {
                continue;
            }

            $date = $slot->slot_date;

            $client = $this->pickClient($clients);

            Appointment::create([
                'reference_number' => $this->reference($date, ++$sequence),
                'user_id' => $client->id,
                'service_id' => $service->id,
                'time_slot_id' => $slot->id,
                'service_window_id' => $service->service_window_id,
                'status' => AppointmentStatus::Confirmed,
                'source' => AppointmentSource::Online,
                'scheduled_at' => Carbon::parse($slot->start_at),
                'queue_date' => $date->toDateString(),
                ...$this->maybePriority($client, $date, unverified: true),
                'confirmed_at' => now(),
            ]);

            $slot->increment('booked_count');
        }
    }

    /* ------------------------------------------------------------------ */

    private function pickClient($clients)
    {
        // Bias toward the known demo accounts so their history is easy to inspect.
        return $clients->whereIn('email', [
            'client@govqueue.test',
            'senior@govqueue.test',
            'pwd@govqueue.test',
            'pregnant@govqueue.test',
        ])->first() ?? $clients->random();
    }

    /**
     * Attach a priority claim, verified for past traffic and left unverified
     * for future bookings (verification happens at the counter).
     */
    private function maybePriority($client, $date, bool $unverified = false): array
    {
        $email = $client->email;

        $claims = match (true) {
            $email === 'pwd@govqueue.test' => ['claims_pwd' => true],
            $email === 'pregnant@govqueue.test' => ['claims_pregnant' => true],
            $email === 'senior@govqueue.test', $client->meetsSeniorCitizenAge() => ['claims_senior_citizen' => true],
            default => [],
        };

        if ($claims === []) {
            return [];
        }

        return [
            ...$claims,
            'priority_reference' => 'DEMO-'.Str::upper(Str::random(6)),
            'priority_verified_at' => $unverified ? null : now()->subDay(),
        ];
    }

    private function writeHistory(Appointment $appointment, bool $noShow = false): void
    {
        $steps = [
            [null, AppointmentStatus::Confirmed->value, 'Booking confirmed'],
            [AppointmentStatus::Confirmed->value, AppointmentStatus::CheckedIn->value, 'Client checked in at the counter'],
        ];

        if (! $noShow) {
            $steps[] = [AppointmentStatus::CheckedIn->value, AppointmentStatus::Serving->value, 'Called to the window'];
            $steps[] = [AppointmentStatus::Serving->value, AppointmentStatus::Completed->value, 'Transaction completed'];
        } else {
            $steps[] = [AppointmentStatus::CheckedIn->value, AppointmentStatus::NoShow->value, 'Client did not appear'];
        }

        foreach ($steps as $i => [$from, $to, $note]) {
            $appointment->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'actor_name' => $i === 0 ? 'System' : 'Demo seeder',
                'note' => $note,
                'created_at' => now()->subMinutes(count($steps) - $i),
            ]);
        }
    }

    private function reference(Carbon $date, int $sequence): string
    {
        return 'GQ-'.$date->format('ymd').'-'.str_pad((string) ($sequence % 1000), 4, '0', STR_PAD_LEFT);
    }
}
