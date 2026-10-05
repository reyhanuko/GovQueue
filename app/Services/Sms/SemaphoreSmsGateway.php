<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Contracts\SmsMessage;
use App\Exceptions\SmsDeliveryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Semaphore SMS (https://semaphore.co) via its REST API.
 *
 * PH numbers must be sent without the "+63" and without the leading zero, which
 * is Semaphore's documented format.
 */
class SemaphoreSmsGateway implements SmsGateway
{
    public function __construct(
        private ?string $apiKey,
        private string $sender = 'GovQueue',
    ) {}

    public function send(SmsMessage $message): void
    {
        if (blank($this->apiKey)) {
            throw SmsDeliveryException::notConfigured($this->name());
        }

        foreach ($message->recipients as $number) {
            $response = Http::asForm()->timeout(20)->post('https://api.semaphore.co/api/v4/messages', [
                'apikey' => $this->apiKey,
                'number' => $this->normalise($number),
                'message' => $message->body,
                'sendername' => $this->sender,
            ]);

            if ($response->failed()) {
                Log::warning('Semaphore rejected an SMS.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw SmsDeliveryException::gatewayError($this->name(), (string) $response->body());
            }
        }
    }

    /**
     * 09171234567 -> 9171234567
     */
    public function normalise(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        if (str_starts_with($digits, '63')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    public function name(): string
    {
        return 'semaphore';
    }
}
