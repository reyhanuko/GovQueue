<?php

namespace App\Exceptions;

use Exception;

/**
 * A gateway refused or failed to deliver a message.
 *
 * Carries the reason so the notification log can show staff why a client was
 * never reached, which matters because the project explicitly does not promise
 * delivery.
 */
class SmsDeliveryException extends Exception
{
    public static function notConfigured(string $gateway): self
    {
        return new self("The {$gateway} SMS gateway is not configured. Set its API key before going live.");
    }

    public static function gatewayError(string $gateway, string $detail): self
    {
        return new self("The {$gateway} gateway returned an error: {$detail}");
    }

    public static function unreachable(string $gateway): self
    {
        return new self("The {$gateway} gateway could not be reached.");
    }

    public static function invalidNumber(string $number): self
    {
        return new self("{$number} is not a valid Philippine mobile number.");
    }
}
