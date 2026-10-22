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

        /*
         * An appointment that can still be moved.
         *
         * Every other appointment here is already checked in, and a checked-in
         * appointment cannot be moved - the screen refuses with "only an
         * upcoming, unstarted appointment can be moved". So without this the
         * whole move-an-appointment flow, and the picker it shares with booking,
         * was unreachable in a browser.
         *
         * A dedicated client, not client@govqueue.test: holding a live
         * appointment makes the booking page show "Booking already held" and
         * disable its confirm button, which would break every booking test.
         */
        $mover = User::firstOrCreate(
            ['email' => 'mover@browser.test'],
            [
                'name' => 'Movable Client',
                'password' => Hash::make('password'),
                'role' => UserRole::Client,
                'phone' => '0917'.random_int(1000000, 9999999),
                'barangay' => 'Poblacion',
                'birthdate' => '1990-06-15',
                'is_active' => true,
            ],
        );

        // Upcoming open days, so moving has somewhere to move to.
        $this->slot($service, $window, $today->copy()->addDay()->setTime(9, 0), 30, capacity: 5);
        $this->slot($service, $window, $today->copy()->addDay()->setTime(9, 30), 30, capacity: 5);
        $this->slot($service, $window, $today->copy()->addDays(2)->setTime(10, 0), 30, capacity: 5);

        Appointment::firstOrCreate(
            ['reference_number' => 'GQ-BROWSER-MOVE'],
            [
                'user_id' => $mover->id,
                'service_id' => $service->id,
                'time_slot_id' => $running->id,
                'service_window_id' => $window->id,
                'status' => 'confirmed',
                'source' => 'online',
                'scheduled_at' => $running->start_at,
                'queue_number' => 'A-050',
                'queue_date' => $today->toDateString(),
                'confirmed_at' => now(),
            ],
        );

        /*
         * Enough completed history that the history list always paginates.
         *
         * The list paginates at eight per page, and the "Newest first" label
         * only renders inside the pagination bar. So with eight or fewer
         * history rows there is no bar, and a test asserting the label depends
         * on how many rows happen to exist rather than on anything it did.
         * It was observed passing in isolation and failing inside the full
         * serial run, for exactly that reason.
         *
         * Ten completed rows guarantees a second page, so the ordering claim the
         * page states is always on screen to be asserted.
         */
        $historyClient = User::where('email', 'client@govqueue.test')->first();

        if ($historyClient) {
            foreach (range(1, 10) as $n) {
                $when = $today->copy()->subDays($n)->setTime(9, 0);
                $pastSlot = $this->slot($service, $window, $when, 30, capacity: 5);

                Appointment::firstOrCreate(
                    ['reference_number' => 'GQ-HIST-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT)],
                    [
                        'user_id' => $historyClient->id,
                        'service_id' => $service->id,
                        'time_slot_id' => $pastSlot->id,
                        'service_window_id' => $window->id,
                        'status' => 'completed',
                        'source' => 'online',
                        'scheduled_at' => $when->toDateTimeString(),
                        'queue_number' => 'A-H'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                        'queue_date' => $when->toDateString(),
                        'confirmed_at' => $when->toDateTimeString(),
                        'checked_in_at' => $when->toDateTimeString(),
                        'completed_at' => $when->copy()->addMinutes(30)->toDateTimeString(),
                    ],
                );
            }
        }

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
                // copy() is not decoration here. Carbon mutates in place, and $today is
                // shared with everything below: without it this line rewinds $today
                // by a week and any later fixture silently gets a date a week in
                // the past. Every other use in this file copies, for that reason.
                'effective_from' => $today->copy()->subWeek()->toDateString(),
                'is_active' => true,
            ],
        );

        /*
         * Two services that exist only to be read on the catalogue card, which
         * has three states and two of which are about absence: no open slots
         * because everything is taken, and no open slots because nothing was
         * ever published. Both used to read "Fully booked for now".
         */
        Service::firstOrCreate(
            ['code' => 'NO-SCHEDULE'],
            [
                'service_window_id' => $window->id,
                'name' => 'Unscheduled Service',
                'description' => 'No published timetable.',
                'fee' => 0,
                'duration_minutes' => 10,
                'is_active' => true,
            ],
        );

        $full = Service::firstOrCreate(
            ['code' => 'FULL-BOOKED'],
            [
                'service_window_id' => $window->id,
                'name' => 'Fully Booked Service',
                'description' => 'Has a timetable, and every place is taken.',
                'fee' => 0,
                'duration_minutes' => 10,
                'is_active' => true,
            ],
        );

        /*
         * One slot, tomorrow, with no room left.
         *
         * The date is worked out ONCE into its own variable. A first version of
         * this called $today->addDay() three times on the same Carbon, which
         * mutates it in place - so slot_date came from the first call and
         * start_at from the third, and the setTime(13, 30) for the end time
         * overwrote the 13:00 start on that same instance. The row that reached
         * the database was not the row this code described.
         *
         * Replaced rather than firstOrCreate'd, because firstOrCreate keys on
         * (service_id, slot_date) and will happily keep a stale row from an
         * earlier run. A card state a test reads has to be exactly this.
         */
        $fullDay = $today->copy()->addDay();

        TimeSlot::where('service_id', $full->id)->delete();

        TimeSlot::create([
            'service_id' => $full->id,
            'service_window_id' => $window->id,
            'slot_date' => $fullDay->toDateString(),
            'start_time' => '13:00:00',
            'end_time' => '13:30:00',
            'start_at' => $fullDay->copy()->setTime(13, 0),
            'end_at' => $fullDay->copy()->setTime(13, 30),
            'capacity' => 1,
            'booked_count' => 1,
            'is_open' => true,
        ]);

        // Same reasoning the other way round: an unscheduled service must have
        // no slots at all, including any a previous run left behind.
        TimeSlot::where('service_id', Service::where('code', 'NO-SCHEDULE')->value('id'))->delete();

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
