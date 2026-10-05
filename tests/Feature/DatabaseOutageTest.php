<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a client sees when the database is unreachable.
 *
 * Reported bug: a raw TimeSlot JSON blob appeared on the booking screen. It was
 * Laravel's debug error page dumping the request context - including every model
 * involved - after a MySQL outage. That is a disclosure problem as much as a
 * cosmetic one: the context holds clients' birthdates, phone numbers, and
 * disability status.
 *
 * These tests pin the replacement behaviour: a plain "temporarily unavailable"
 * page, with the detail going to the log instead of to the browser.
 */
class DatabaseOutageTest extends TestCase
{
    /**
     * The messages MySQL actually produces when the server is down or the
     * connection drops mid-request. Matched by the handler in bootstrap/app.php.
     */
    public static function outageMessages(): array
    {
        return [
            'refused' => 'SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it (Connection: mysql, Host: 127.0.0.1, Port: 3306)',
            'gone away' => 'SQLSTATE[HY000] [2006] MySQL server has gone away (Connection: mysql)',
            'lock wait' => 'SQLSTATE[HY008] (Connection: mysql, SQL: select * from `sessions`)',
            'lost' => 'Lost connection to MySQL server at \'reading initial communication packet\'',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A route that fails the way a dead database does. Thrown lazily so the
        // test controls the exact message.
        Route::get('/__outage', function () {
            throw new \RuntimeException(request()->query('message', 'boom'));
        });
    }

    public static function connectionFailureProvider(): array
    {
        return array_map(fn ($m) => [$m], array_keys(self::outageMessages()));
    }

    #[DataProvider('connectionFailureProvider')]
    public function test_a_connection_failure_shows_a_friendly_page_not_a_stack_trace(string $message): void
    {
        $response = $this->get('/__outage?message='.urlencode(self::outageMessages()[$message]));

        $response->assertStatus(503);
        $response->assertSee('temporarily unavailable');
        $response->assertSee('We could not reach the queue system');

        // Nothing about the infrastructure, and above all no stack trace.
        $response->assertDontSee('SQLSTATE');
        $response->assertDontSee('vendor/laravel/framework');
        $response->assertDontSee('Stack trace');
    }

    public function test_the_unavailable_page_never_exposes_model_data(): void
    {
        // This is the shape of what Laravel's debug page was dumping.
        $leak = '{"id":4912,"service_id":6,"service_window_id":2,"capacity":4}';

        $response = $this->get('/__outage?message='.urlencode(
            'SQLSTATE[HY000] [2006] MySQL server has gone away'.$leak
        ));

        $response->assertStatus(503);
        $response->assertDontSee('4912');
        $response->assertDontSee('service_window_id');
    }

    public function test_api_and_ajax_clients_get_a_clean_503_instead_of_html(): void
    {
        $this->getJson('/__outage?message='.urlencode(self::outageMessages()['refused']))
            ->assertStatus(503)
            ->assertJson([
                'message' => 'The service is temporarily unavailable. Please try again shortly.',
            ]);
    }

    public function test_the_unavailable_page_is_branded(): void
    {
        // Someone standing at a service window should still see the LGU chrome,
        // not a bare unstyled error.
        $response = $this->get('/__outage?message='.urlencode(self::outageMessages()['refused']));

        $response->assertStatus(503);
        $this->assertStringContainsString('</html>', $response->getContent());
    }

    public function test_the_live_queue_display_degrades_gracefully(): void
    {
        // The lobby screen is the one place nobody can retry a click on, so it
        // must not 500 when the database is down.
        $this->get('/queue-display')->assertStatus(200);
    }
}
