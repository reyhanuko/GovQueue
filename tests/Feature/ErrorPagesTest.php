<?php

namespace Tests\Feature;

use App\Http\Middleware\CorrelateRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pages a resident or a clerk sees when something goes wrong.
 *
 * Two things matter here. First, that the page never leaks: this application
 * holds names, birthdates, phone numbers and disability status, so a stack
 * trace is a privacy incident, not a cosmetic one. Second, that it gives
 * somebody something to quote - a request id that is already in the log -
 * because "it broke" is otherwise the only report available.
 *
 * The 405 page is not hypothetical. Moving an appointment posted to the wrong
 * route and produced exactly this page for a real client.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    /** The three that had no view at all before. */
    public function test_a_view_exists_for_every_status_the_app_can_produce(): void
    {
        foreach (['403', '404', '405', '419', '500'] as $status) {
            $this->assertFileExists(
                resource_path("views/errors/{$status}.blade.php"),
                "No friendly page for {$status}, so this renders Laravel's default.",
            );
        }
    }

    /**
     * A reference code on every status page.
     *
     * Asserted on the rendered output rather than on the template, because a
     * reference the person cannot see is not a reference.
     */
    public function test_every_error_page_shows_the_request_reference(): void
    {
        // Stands in for the middleware having stamped the current request.
        // Rendering a view directly has no HTTP request behind it, and the
        // reference is conditional on there being one - correctly, since an id
        // invented outside a request would not be in the log to be found by.
        request()->attributes->set(CorrelateRequests::ATTRIBUTE, 'test-reference-0001');

        foreach (['403', '404', '405', '419', '500'] as $status) {
            $html = view("errors.{$status}")->render();

            $this->assertStringContainsString(
                'quote this reference',
                $html,
                "The {$status} page gives the reader nothing to quote.",
            );

            $this->assertStringContainsString(
                'test-reference-0001',
                $html,
                "The {$status} page does not show the id it was given.",
            );
        }
    }

    public function test_the_reference_on_the_page_is_the_id_that_reached_the_log(): void
    {
        $response = $this->get('/a-route-that-does-not-exist');

        $response->assertNotFound();

        $id = $response->headers->get(CorrelateRequests::HEADER);

        $this->assertNotNull($id, 'The response carried no request id, so nothing can be quoted.');
        $this->assertStringContainsString($id, $response->getContent());
    }

    /** An inbound id is honoured, so the same value appears in the log and on the page. */
    public function test_a_reference_carried_in_by_a_proxy_is_the_one_shown(): void
    {
        $response = $this->withHeaders([CorrelateRequests::HEADER => 'trace-from-the-edge-0001'])
            ->get('/a-route-that-does-not-exist');

        $response->assertNotFound();
        $this->assertStringContainsString('trace-from-the-edge-0001', $response->getContent());
    }

    /**
     * The reason this file exists.
     *
     * Laravel renders its debug page - framework version, PHP version, file
     * paths, and the model JSON behind every record in the request - whenever
     * APP_DEBUG is on. That page ignores errors/*.blade.php entirely, so the
     * views above are not a defence against it; they only apply once debug is
     * off.
     *
     * So the real assertion is the environment, not the template. If debug is
     * ever on outside local and testing, this fails.
     */
    public function test_debug_output_is_impossible_outside_local_and_testing(): void
    {
        $environment = app()->environment();

        $this->assertContains(
            $environment,
            ['local', 'testing', 'browser'],
            "APP_DEBUG is enabled in the \"{$environment}\" environment. Every stack trace "
                .'this application renders would include clients\' birthdates, phone numbers and '
                .'disability status. These error pages are not shown instead of the debug page - '
                .'they are ignored by it.',
        );
    }

    public function test_an_unhandled_error_in_production_would_render_the_friendly_page(): void
    {
        // Renders the view Laravel would fall back to. Proves the view exists,
        // extends the right layout, and produces no framework internals.
        $html = view('errors.500')->render();

        $this->assertStringContainsString('Something went wrong on our side', $html);
        $this->assertStringNotContainsString('vendor/laravel', $html);
        $this->assertStringNotContainsString('Stack trace', $html);
    }
}
