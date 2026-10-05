<?php

namespace Tests\Unit;

use App\Contracts\SmsMessage;
use App\Exceptions\SmsDeliveryException;
use App\Services\SmsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The SMS provider abstraction.
 *
 * The gateways are tested against a faked HTTP client, so the real API shapes are
 * verified without sending anything. The project's stated limitation is that
 * delivery is not guaranteed; these tests pin down what happens when a gateway
 * fails.
 */
class SmsGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): SmsManager
    {
        return new SmsManager;
    }

    /* ------------------------------------------------------------------ */
    /* Message */
    /* ------------------------------------------------------------------ */

    public function test_a_message_needs_at_least_one_recipient(): void
    {
        $this->expectException(\RuntimeException::class);

        new SmsMessage([], 'hello');
    }

    public function test_a_message_needs_a_body(): void
    {
        $this->expectException(\RuntimeException::class);

        new SmsMessage(['09171234567'], '   ');
    }

    /* ------------------------------------------------------------------ */
    /* Log driver */
    /* ------------------------------------------------------------------ */

    public function test_the_log_driver_writes_instead_of_sending(): void
    {
        Log::spy();

        config(['govqueue.sms.driver' => 'log']);

        $gateway = $this->manager()->gateway();
        $gateway->send(new SmsMessage(['09171234567'], 'Your appointment is tomorrow.'));

        $this->assertSame('log', $gateway->name());

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context) => str_contains($message, 'Your appointment is tomorrow'));
    }

    public function test_the_log_driver_is_the_default(): void
    {
        // A fresh checkout must work with no paid gateway configured.
        $this->assertSame('log', config('govqueue.sms.driver'));
    }

    /* ------------------------------------------------------------------ */
    /* Semaphore */
    /* ------------------------------------------------------------------ */

    public function test_semaphore_normalises_a_ph_mobile_number(): void
    {
        config([
            'govqueue.sms.driver' => 'semaphore',
            'govqueue.sms.semaphore.api_key' => 'key-123',
        ]);

        $gateway = $this->manager()->gateway();

        $this->assertSame('9171234567', $gateway->normalise('09171234567'));
        $this->assertSame('9171234567', $gateway->normalise('+63 917 123 4567'));
        $this->assertSame('9171234567', $gateway->normalise('639171234567'));
    }

    public function test_semaphore_posts_to_its_api(): void
    {
        Http::fake([
            'api.semaphore.co/*' => Http::response(['message' => 'sent'], 200),
        ]);

        config([
            'govqueue.sms.driver' => 'semaphore',
            'govqueue.sms.semaphore.api_key' => 'key-123',
            'govqueue.sms.semaphore.sender' => 'GovQueue',
        ]);

        $this->manager()->gateway()->send(new SmsMessage(['09171234567'], 'Reminder: appointment tomorrow.'));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.semaphore.co/api/v4/messages'
                && $request['number'] === '9171234567'
                && $request['apikey'] === 'key-123'
                && $request['message'] === 'Reminder: appointment tomorrow.'
                && $request['sendername'] === 'GovQueue';
        });
    }

    public function test_semaphore_throws_when_it_has_no_api_key(): void
    {
        config([
            'govqueue.sms.driver' => 'semaphore',
            'govqueue.sms.semaphore.api_key' => null,
        ]);

        Http::fake();

        $this->expectException(SmsDeliveryException::class);
        $this->expectExceptionMessage('not configured');

        $this->manager()->gateway()->send(new SmsMessage(['09171234567'], 'hello'));
    }

    public function test_a_semaphore_error_becomes_a_delivery_exception(): void
    {
        Http::fake([
            'api.semaphore.co/*' => Http::response(['error' => 'Invalid API key'], 401),
        ]);

        config([
            'govqueue.sms.driver' => 'semaphore',
            'govqueue.sms.semaphore.api_key' => 'bad-key',
        ]);

        $this->expectException(SmsDeliveryException::class);
        $this->expectExceptionMessage('Invalid API key');

        $this->manager()->gateway()->send(new SmsMessage(['09171234567'], 'hello'));
    }

    public function test_semaphore_sends_one_request_per_recipient(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response([], 200)]);

        config([
            'govqueue.sms.driver' => 'semaphore',
            'govqueue.sms.semaphore.api_key' => 'key-123',
        ]);

        $this->manager()->gateway()->send(new SmsMessage(['09171234567', '09181234568'], 'hello'));

        Http::assertSentCount(2);
    }

    /* ------------------------------------------------------------------ */
    /* TextMe */
    /* ------------------------------------------------------------------ */

    public function test_textme_posts_to_its_api(): void
    {
        Http::fake(['api.textme.com/*' => Http::response(['status' => 'ok'], 200)]);

        config([
            'govqueue.sms.driver' => 'textme',
            'govqueue.sms.textme.api_key' => 'tm-key',
        ]);

        $this->manager()->gateway()->send(new SmsMessage(['09171234567'], 'Confirmed.'));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.textme.com/api/sms'
            && $r['api_key'] === 'tm-key'
            && $r['to'] === '09171234567'
            && $r['message'] === 'Confirmed.');
    }

    public function test_textme_throws_when_it_has_no_api_key(): void
    {
        config(['govqueue.sms.driver' => 'textme', 'govqueue.sms.textme.api_key' => null]);

        $this->expectException(SmsDeliveryException::class);

        $this->manager()->gateway()->send(new SmsMessage(['09171234567'], 'hello'));
    }

    /* ------------------------------------------------------------------ */
    /* Manager */
    /* ------------------------------------------------------------------ */

    public function test_an_unknown_driver_is_rejected_clearly(): void
    {
        config(['govqueue.sms.driver' => 'carrier-pigeon']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown SMS driver');

        $this->manager()->gateway();
    }

    public function test_the_manager_warns_when_a_paid_gateway_has_no_key(): void
    {
        config([
            'govqueue.sms.driver' => 'semaphore',
            'govqueue.sms.semaphore.api_key' => null,
        ]);

        $manager = $this->manager();

        $this->assertFalse($manager->isConfigured());
        $this->assertStringContainsString('no API key', (string) $manager->configurationWarning());
    }

    public function test_the_manager_does_not_warn_for_the_log_driver(): void
    {
        config(['govqueue.sms.driver' => 'log']);

        $manager = $this->manager();

        $this->assertTrue($manager->isConfigured());
        $this->assertNull($manager->configurationWarning());
    }

    public function test_the_gateway_is_resolved_once_per_manager(): void
    {
        config(['govqueue.sms.driver' => 'log']);

        $manager = $this->manager();

        $this->assertSame($manager->gateway(), $manager->gateway());
    }
}
