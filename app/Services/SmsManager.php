<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use App\Contracts\SmsMessage;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\SemaphoreSmsGateway;
use App\Services\Sms\TextmeSmsGateway;
use InvalidArgumentException;

/**
 * Resolves the configured SMS gateway.
 *
 * Registered as a container singleton so the gateway is built once per request
 * rather than per message, which matters when the reminder job fans out to
 * hundreds of appointments.
 */
class SmsManager implements SmsGateway
{
    private ?SmsGateway $gateway = null;

    public function send(SmsMessage $message): void
    {
        $this->gateway()->send($message);
    }

    public function name(): string
    {
        return $this->gateway()->name();
    }

    public function gateway(): SmsGateway
    {
        return $this->gateway ??= $this->resolve();
    }

    public function swap(SmsGateway $gateway): void
    {
        $this->gateway = $gateway;
    }

    private function resolve(): SmsGateway
    {
        $driver = config('govqueue.sms.driver', 'log');

        return match ($driver) {
            'log' => new LogSmsGateway,
            'semaphore' => new SemaphoreSmsGateway(
                config('govqueue.sms.semaphore.api_key'),
                (string) config('govqueue.sms.semaphore.sender', 'GovQueue'),
            ),
            'textme' => new TextmeSmsGateway(config('govqueue.sms.textme.api_key')),
            default => throw new InvalidArgumentException(
                "Unknown SMS driver [{$driver}]. Expected log, semaphore, or textme."
            ),
        };
    }

    /**
     * Is the gateway usable right now?
     *
     * An unconfigured gateway is reported rather than throwing, so the admin
     * screen can warn staff before a client sits waiting for a text that will
     * never arrive.
     */
    public function isConfigured(): bool
    {
        try {
            return match ($this->gateway()->name()) {
                'semaphore' => filled(config('govqueue.sms.semaphore.api_key')),
                'textme' => filled(config('govqueue.sms.textme.api_key')),
                default => true,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Human-readable configuration warning, if any.
     */
    public function configurationWarning(): ?string
    {
        if ($this->isConfigured()) {
            return null;
        }

        try {
            $name = $this->gateway()->name();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return "SMS is switched on but the {$name} gateway has no API key, "
            .'so messages are logged instead of sent. Delivery is not guaranteed.';
    }
}
