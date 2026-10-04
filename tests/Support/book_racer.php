<?php

use App\Exceptions\AppointmentConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Contracts\Console\Kernel;

/**
 * Parallel booking worker used by BookingConcurrencyTest.
 *
 * Boots the application against the test database and races a single client
 * for one slot, printing "won" or "lost" on stdout. Started as a separate
 * process by the test so several transactions genuinely contend for the same
 * row lock rather than taking turns inside one PHP process.
 *
 * Usage: php book_racer.php <user_id> <service_id> <slot_id> <gate_file>
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $serviceId, $slotId] = $argv;

/*
 * Spin on the gate file so every worker is at the INSERT at the same moment.
 * The parent touches it once the last worker has been spawned.
 */
if (isset($argv[4]) && $argv[4] !== '') {
    $waited = 0;
    while (! file_exists($argv[4]) && $waited < 10000) {
        usleep(200);
        $waited++;
    }
}

try {
    $user = User::findOrFail((int) $userId);
    $service = Service::findOrFail((int) $serviceId);

    $appointment = app(BookingService::class)
        ->book($user, $service, (int) $slotId);

    echo 'won:'.$appointment->queue_number."\n";
} catch (SlotUnavailableException $e) {
    echo "lost\n";
} catch (AppointmentConflictException $e) {
    echo "lost\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    echo "lost\n";
}
