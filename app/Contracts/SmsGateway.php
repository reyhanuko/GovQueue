<?php

namespace App\Contracts;

use App\Exceptions\SmsDeliveryException;

/**
 * A gateway that can deliver SMS.
 *
 * Implementations must throw on failure rather than returning false, so a failed
 * send is recorded against the notification log instead of being silently lost.
 */
interface SmsGateway
{
    /**
     * @throws SmsDeliveryException on failure
     */
    public function send(SmsMessage $message): void;

    /** Short identifier for logs and the admin screen, e.g. "log" or "semaphore". */
    public function name(): string;
}
