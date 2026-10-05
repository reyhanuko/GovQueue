<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Contracts\SmsMessage;
use Illuminate\Support\Facades\Log;

/**
 * Writes SMS to the application log instead of sending them.
 *
 * This is the default driver so the notification feature is fully demonstrable
 * on a fresh checkout with no paid gateway and no API keys. It reports success
 * because the message really was accepted - by the log - and the admin screen
 * labels the driver so nobody mistakes log output for a real text message.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(SmsMessage $message): void
    {
        Log::info('[GovQueue SMS:log] '.$message->body, [
            'to' => $message->recipients,
            'driver' => $this->name(),
        ]);
    }

    public function name(): string
    {
        return 'log';
    }
}
