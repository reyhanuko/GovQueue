<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A fixed, predictable world for the browser suite.
 *
 * The demo seeder gives volume - useful for the PDF and the reports screens -
 * but it generates random figures, so a test cannot assert "queue number A-002
 * is third in the list". These fixtures add the handful of records the browser
 * tests need to name exactly.
 *
 * Deliberately additive: it runs after DatabaseSeeder and DemoActivitySeeder
 * rather than replacing them, so the browser environment looks like a populated
 * office rather than a clean install.
 */
class BrowserFixturesSeeder extends Seeder
{
    public function run(): void
    {
        $today = today();

        /*
         * Window 1 is the staff member's own, because the console is now pinned
         * to it. A test that signs in as staff1 and expects to act on a ticket
         * has to be looking at that window's queue.
         */
        $window = ServiceWindow::firstOrCreate(
            ['code' => 'WINDOW_1'],
            [
                'name' => 'Window 1 - Certificates',
                'avg_service_minutes' => 12,
                'is_active' => true,
            ],
        );

        $service = Service::firstOrCreate(
            ['code' => 'CERT-IND'],
            [
                'service_window_id' => $window->id,
                'name' => 'Certificate of Indigency',
                'description' => 'Issued to qualifying residents of the municipality.',
                'fee' => 50,
                'duration_minutes' => 12,
                'requires_document' => true,
                'is_active' => true,
            ],
        );

        $staff = User::firstOrCreate(
            ['email' => 'staff1@govqueue.test'],
            [
                'name' => 'Rosa Mendoza',
                'password' => Hash::make('password'),
                'role' => UserRole::Staff,
                'service_window_id' => $window->id,
                'phone' => '09171234567',
                'is_active' => true,
            ],
        );

        /*
         * Slots the browser tests can name.
         *
         * Today at 09:00 is FINISHED by the time any of this runs, which is what
         * the read-only assertions need. 23:00 is still running, which is what
         * "a slot can still be changed while it is being served" needs. Keeping
         * both means one fixture covers the boundary from either direction,
         * without a test depending on the wall clock.
         */
        $finished = $this->slot($service, $window, $today->copy()->setTime(9, 0), 30, capacity: 5);
        $running = $this->slot($service, $window, $today->copy()->setTime(23, 0), 30, capacity: 5);
        $removable = $this->slot($service, $window, $today->copy()->setTime(23, 30), 30, capacity: 5);

        $yesterday = $today->copy()->subDay();
        $this->slot($service, $window, $yesterday->copy()->setTime(9, 0), 30, capacity: 5);

        /*
         * A known queue, in a known order, on the window staff1 works.
         *
         * Created explicitly rather than left to the demo seeder so the tests can
         * assert a specific client is a specific position. The two clients are
         * checked in at known minutes so the ordering is stable regardless of
         * when the suite runs.
         */
        $clients = collect(['Ana Dela Cruz', 'Ben Reyes'])->map(fn (string $name) => User::firstOrCreate(
            ['email' => str($name)->slug().'@browser.test'],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'role' => UserRole::Client,
                'phone' => '0917'.random_int(1000000, 9999999),
                'barangay' => 'Poblacion',
                'birthdate' => '1990-06-15',
                'is_active' => true,
            ],
        ));

        $clients->each(function (User $client, int $i) use ($service, $window, $running, $today) {
            Appointment::firstOrCreate(
                ['reference_number' => 'GQ-BROWSER-00'.($i + 1)],
                [
                    'user_id' => $client->id,
                    'service_id' => $service->id,
                    'time_slot_id' => $running->id,
                    'service_window_id' => $window->id,
                    'status' => 'checked_in',
                    'source' => 'online',
                    'scheduled_at' => $running->start_at,
                    'queue_number' => 'A-00'.($i + 1),
                    'queue_date' => $today->toDateString(),
                    'confirmed_at' => now(),
                    'checked_in_at' => now()->subMinutes(30 - ($i * 10)),
                ],
            );
        });

        // A template, so the slot-template screen has something to edit or remove.
        SlotTemplate::firstOrCreate(
            [
                'service_id' => $service->id,
                'day_of_week' => (int) $today->copy()->next('Monday')->dayOfWeekIso,
                'start_time' => '08:00:00',
            ],
            [
                'end_time' => '12:00:00',
                'slot_minutes' => 30,
                'capacity' => 5,
                'effective_from' => $today->subWeek()->toDateString(),
                'is_active' => true,
            ],
        );

        $this->command?->info('Browser fixtures ready.');
    }

    private function slot(Service $service, ServiceWindow $window, Carbon $start, int $minutes, int $capacity): TimeSlot
    {
        return TimeSlot::firstOrCreate(
            [
                'service_id' => $service->id,
                'slot_date' => $start->toDateString(),
                'start_at' => $start->toDateTimeString(),
            ],
            [
                'service_window_id' => $window->id,
                'start_time' => $start->format('H:i:s'),
                'end_time' => $start->copy()->addMinutes($minutes)->format('H:i:s'),
                'end_at' => $start->copy()->addMinutes($minutes)->toDateTimeString(),
                'capacity' => $capacity,
                'booked_count' => 0,
                'is_open' => true,
            ],
        );
    }
}
