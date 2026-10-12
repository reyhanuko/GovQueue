<?php

use App\Exceptions\AppointmentConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Service;
use App\Services\BookingService;
use Illuminate\Contracts\Console\Kernel;

/**
 * Parallel walk-in worker used by BookingConcurrencyTest.
 *
 * The walk-in equivalent of book_racer.php. It exists because walk-in queue
 * numbers were issued from an unlocked MAX() read before the transaction opened,
 * so two counters pressing "issue walk-in" at the same moment both computed the
 * same W- number and the lobby board displayed it twice.
 *
 * Separate from the booking racer on purpose: a walk-in carries no user id (the
 * service creates or matches the client itself) and goes through a different
 * code path, so sharing one script would have tested neither properly.
 *
 * Usage: php walkin_racer.php <service_id> <label> <gate_file>
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $serviceId, $label] = $argv;
$gate = $argv[3] ?? null;

/*
 * Spin on the gate file so every worker reaches the transaction at the same
 * moment. Without it the workers take turns and the race never happens.
 */
if ($gate !== null && $gate !== '') {
    $waited = 0;
    while (! file_exists($gate) && $waited < 10000) {
        usleep(200);
        $waited++;
    }
}

try {
    $service = Service::findOrFail((int) $serviceId);

    $appointment = app(BookingService::class)->createWalkIn(
        [
            'name' => 'Racer '.$label,
            // Distinct phone numbers so the duplicate guard - which matches on
            // name and number - does not refuse these as the same person and
            // mask the numbering race we are actually testing.
            'phone' => '0917'.str_pad((string) $label, 7, '0', STR_PAD_LEFT),
            'service_id' => (int) $serviceId,
        ],
        null,
        true,
    );

    echo 'won:'.$appointment->queue_number."\n";
} catch (SlotUnavailableException $e) {
    echo "lost\n";
} catch (AppointmentConflictException $e) {
    echo "lost\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    echo "lost\n";
}
