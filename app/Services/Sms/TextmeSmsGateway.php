<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Contracts\SmsMessage;
use App\Exceptions\SmsDeliveryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TextMe SMS (https://textme.com) via its REST API.
 */
class TextmeSmsGateway implements SmsGateway
{
    public function __construct(private ?string $apiKey) {}

    public function send(SmsMessage $message): void
    {
        if (blank($this->apiKey)) {
            throw SmsDeliveryException::notConfigured($this->name());
        }

        foreach ($message->recipients as $number) {
            $response = Http::asForm()->timeout(20)->post('https://api.textme.com/api/sms', [
                'api_key' => $this->apiKey,
                'to' => $this->normalise($number),
                'message' => $message->body,
            ]);

            if ($response->failed()) {
                Log::warning('TextMe rejected an SMS.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw SmsDeliveryException::gatewayError($this->name(), (string) $response->body());
            }
        }
    }

    public function normalise(string $number): string
    {
        return preg_replace('/\D/', '', $number) ?? '';
    }

    public function name(): string
    {
        return 'textme';
    }
}
