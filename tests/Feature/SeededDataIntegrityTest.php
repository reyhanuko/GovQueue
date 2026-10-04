<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the data-integrity invariants that the seeded demo data must respect.
 *
 * These failed before the Sprint 4 audit: the seeder incremented booked_count
 * for tickets that had already been served, and assigned queue numbers without
 * maintaining the backing counter row. Both produced data the rest of the
 * application would treat as authoritative.
 */
class SeededDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE = ['pending', 'confirmed', 'checked_in', 'serving'];

    public function test_booked_count_equals_the_number_of_live_appointments(): void
    {
        $this->seed(DatabaseSeeder::class);

        $slots = DB::table('time_slots')->where('booked_count', '>', 0)->get();
        $this->assertNotEmpty($slots, 'The demo data should contain bookings to check.');

        foreach ($slots as $slot) {
            $live = DB::table('appointments')
                ->where('time_slot_id', $slot->id)
                ->whereIn('status', self::LIVE)
                ->count();

            $this->assertSame($live, (int) $slot->booked_count,
                "Slot {$slot->id}: booked_count does not match its live appointments.");
        }
    }

    public function test_no_slot_is_booked_beyond_capacity(): void
    {
        $this->seed(DatabaseSeeder::class);

        $over = DB::table('time_slots')->whereColumn('booked_count', '>', 'capacity')->count();

        $this->assertSame(0, $over, 'Seeded data must never exceed a slot\'s capacity.');
    }

    public function test_no_slot_has_a_negative_counter(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, DB::table('time_slots')->where('booked_count', '<', 0)->count());
    }

    public function test_queue_numbers_are_unique_per_window_and_day(): void
    {
        $this->seed(DatabaseSeeder::class);

        $duplicates = DB::table('appointments')
            ->whereNotNull('queue_number')
            ->selectRaw('service_window_id, queue_date, queue_number, COUNT(*) c')
            ->groupBy('service_window_id', 'queue_date', 'queue_number')
            ->having('c', '>', 1)
            ->get();

        $this->assertCount(0, $duplicates,
            'Duplicate queue numbers: '.$duplicates->map(fn ($d) => $d->queue_number)->implode(', '));
    }

    public function test_every_queue_numbered_ticket_has_a_backing_counter_row(): void
    {
        $this->seed(DatabaseSeeder::class);

        $orphans = DB::table('appointments')
            ->whereNotNull('queue_number')
            ->where('queue_number', 'like', 'A-%')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('queue_counters')
                    ->whereColumn('queue_counters.service_window_id', 'appointments.service_window_id')
                    ->whereColumn('queue_counters.queue_date', 'appointments.queue_date');
            })
            ->count();

        $this->assertSame(0, $orphans,
            'Tickets exist with no counter row, so the next booking would collide with them.');
    }

    public function test_a_booking_after_seeding_does_not_collide_with_a_seeded_ticket(): void
    {
        $this->seed(DatabaseSeeder::class);

        $service = Service::where('code', 'CERT-IND')->firstOrFail();
        $slot = TimeSlot::where('service_id', $service->id)
            ->whereDate('slot_date', '>=', today()->toDateString())
            ->where('is_open', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('start_at', '>=', now())
            ->orderBy('start_at')
            ->firstOrFail();

        $client = User::factory()->client()->create();

        $appointment = app(BookingService::class)->book($client, $service, $slot->id);

        $collides = Appointment::where('service_window_id', $appointment->service_window_id)
            ->whereDate('queue_date', $appointment->queue_date)
            ->where('queue_number', $appointment->queue_number)
            ->where('id', '!=', $appointment->id)
            ->exists();

        $this->assertFalse($collides,
            "Issued {$appointment->queue_number} which is already in use that day.");
    }

    public function test_seeded_appointments_agree_with_their_slot(): void
    {
        $this->seed(DatabaseSeeder::class);

        $mismatched = DB::table('appointments as a')
            ->join('time_slots as s', 'a.time_slot_id', '=', 's.id')
            ->where(function ($q) {
                $q->whereColumn('a.service_id', '!=', 's.service_id')
                    ->orWhereColumn('a.service_window_id', '!=', 's.service_window_id');
            })
            ->count();

        $this->assertSame(0, $mismatched);
    }

    public function test_scheduled_at_matches_the_booked_slot_start(): void
    {
        $this->seed(DatabaseSeeder::class);

        $drifted = DB::table('appointments as a')
            ->join('time_slots as s', 'a.time_slot_id', '=', 's.id')
            ->whereNotNull('a.scheduled_at')
            ->whereColumn('a.scheduled_at', '!=', 's.start_at')
            ->count();

        $this->assertSame(0, $drifted);
    }

    public function test_no_appointment_is_marked_verified_without_a_claim(): void
    {
        $this->seed(DatabaseSeeder::class);

        $bogus = DB::table('appointments')
            ->whereNotNull('priority_verified_at')
            ->where('claims_senior_citizen', false)
            ->where('claims_pwd', false)
            ->where('claims_pregnant', false)
            ->count();

        $this->assertSame(0, $bogus);
    }

    public function test_no_senior_claim_for_a_client_under_sixty(): void
    {
        $this->seed(DatabaseSeeder::class);

        $bogus = DB::table('appointments as a')
            ->join('users as u', 'a.user_id', '=', 'u.id')
            ->where('a.claims_senior_citizen', true)
            ->whereNotNull('u.birthdate')
            ->whereRaw('TIMESTAMPDIFF(YEAR, u.birthdate, CURDATE()) < ?',
                [(int) config('govqueue.senior_citizen_age')])
            ->count();

        $this->assertSame(0, $bogus,
            'RA 9994 senior priority must not be granted below the age threshold.');
    }

    public function test_the_demo_data_contains_a_live_queue_with_priority_cases(): void
    {
        $this->seed(DatabaseSeeder::class);

        $today = today()->toDateString();

        $live = Appointment::whereDate('queue_date', $today)
            ->whereIn('status', [AppointmentStatus::Confirmed->value, AppointmentStatus::CheckedIn->value])
            ->count();

        $verifiedPriority = Appointment::whereDate('queue_date', $today)
            ->whereNotNull('priority_verified_at')
            ->count();

        $this->assertGreaterThan(0, $live, 'The staff console needs a live queue to demo.');
        $this->assertGreaterThan(0, $verifiedPriority,
            'The queue needs at least one verified priority case to demo RA 9994 / RA 10754.');
    }
}
