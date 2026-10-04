<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ServiceWindow;
use App\Models\User;
use App\Services\ClientProfileService;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@govqueue.test'],
            [
                'name' => 'Municipal Administrator',
                // The model casts `password` to 'hashed', so plain strings are hashed on save.
                'password' => 'password',
                'role' => UserRole::Admin,
                'phone' => '09171234567',
                'barangay' => 'Poblacion',
                'birthdate' => '1975-05-12',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // Windows may not exist yet on a truly fresh database, so resolve the
        // ids defensively and leave the assignment null when absent.
        $windows = ServiceWindow::pluck('id', 'code');

        collect([
            ['staff1@govqueue.test', 'Rosa Mendoza', 'WINDOW_1', '09171234568'],
            ['staff2@govqueue.test', 'Jomar Bautista', 'WINDOW_2', '09171234569'],
            ['staff3@govqueue.test', 'Nina Ravelo', 'WINDOW_3', '09171234570'],
        ])->each(function (array $row) use ($windows) {
            [$email, $name, $windowCode, $phone] = $row;

            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    // The model casts `password` to 'hashed', so plain strings are hashed on save.
                    'password' => 'password',
                    'role' => UserRole::Staff,
                    'phone' => $phone,
                    'barangay' => 'Poblacion',
                    'birthdate' => '1988-03-22',
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'service_window_id' => $windows[$windowCode] ?? null,
                ],
            );
        });

        // Demo clients: one ordinary, plus one senior citizen (RA 9994), one PWD
        // (RA 10754), and one pregnant client so the priority queue is
        // demonstrable immediately after seeding.
        $demoClients = [
            ['client@govqueue.test', 'Ana Dela Cruz', '1994-07-08', '09181234567', 'Poblacion'],
            ['senior@govqueue.test', 'Lorenzo Abalos', '1958-02-19', '09181234568', 'Lime'],
            ['pwd@govqueue.test', 'Marisa Lim', '1985-11-30', '09181234569', 'Crossing'],
            ['pregnant@govqueue.test', 'Juvylyn Petal', '1998-09-14', '09181234570', 'Tubod'],
        ];

        $profileService = app(ClientProfileService::class);

        foreach ($demoClients as [$email, $name, $birthdate, $phone, $barangay]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    // The model casts `password` to 'hashed', so plain strings are hashed on save.
                    'password' => 'password',
                    'role' => UserRole::Client,
                    'phone' => $phone,
                    'address' => 'Poblacion',
                    'barangay' => $barangay,
                    'birthdate' => $birthdate,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );

            // Derive the RA 9994 entitlement from the birthdate rather than
            // hard-coding it, so the demo data cannot drift from the rules.
            $profileService->syncSeniorCitizenClaim($user);
        }

        User::factory()->count(10)->client()->create();
    }
}
