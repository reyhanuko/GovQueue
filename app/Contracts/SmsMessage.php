<?php

namespace App\Contracts;

use RuntimeException;

/**
 * An outbound SMS.
 *
 * GovQueue never talks to a provider directly; everything goes through this so
 * the office can change gateway without touching business logic, and so the
 * project can be demonstrated with no paid account.
 */
class SmsMessage
{
    /**
     * @param  list<string>  $recipients  E.164 or local mobile numbers
     */
    public function __construct(
        public readonly array $recipients,
        public readonly string $body,
    ) {
        if ($recipients === []) {
            throw new RuntimeException('An SMS needs at least one recipient.');
        }

        if (trim($body) === '') {
            throw new RuntimeException('An SMS needs a non-empty body.');
        }
    }

    public function firstRecipient(): string
    {
        return $this->recipients[0];
    }
}
