<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\SlotTemplate;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structural accessibility checks across every screen.
 *
 * A full audit run over the live site found two real defects that no unit test
 * was watching for:
 *
 *  - The slot-template page renders one form per template, and every field used
 *    the same element id. Duplicate ids mean label[for] and aria-describedby
 *    silently resolve to whichever copy appears first in the document, so
 *    clicking the "Opens" label on the fourth template focused the *add* form's
 *    field, and its help text was announced for the wrong control.
 *  - The client's appointment history had four <th> with no scope, so a screen
 *    reader could not tell column headers from row headers.
 *
 * These assertions are cheap and catch that whole class of mistake.
 */
class AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Pages reachable without an account. */
    private function publicPages(): array
    {
        return ['/', '/services', '/queue-display', '/login', '/register'];
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument;

        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /** Every id present in the document, mapped to how often it appears. */
    private function idCounts(DOMXPath $xp): array
    {
        $counts = [];

        foreach ($xp->query('//*[@id]') as $el) {
            $id = $el->getAttribute('id');
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }

    private function assertNoDuplicateIds(string $html, string $context): void
    {
        foreach ($this->idCounts($this->dom($html)) as $id => $count) {
            $this->assertSame(
                1,
                $count,
                "Duplicate id \"{$id}\" appears {$count} times in {$context}. Label and "
                    .'ARIA references resolve to the first one in the document, not the intended control.'
            );
        }
    }

    private function assertEveryControlIsLabelled(string $html, string $context): void
    {
        $xp = $this->dom($html);

        foreach ($xp->query('//input | //select | //textarea') as $el) {
            $type = strtolower($el->getAttribute('type'));

            if (in_array($type, ['hidden', 'submit', 'button', 'image'], true)) {
                continue;
            }

            $name = $el->getAttribute('name') ?: '(unnamed)';
            $labelled = $el->getAttribute('aria-label') !== ''
                || $el->getAttribute('aria-labelledby') !== ''
                || ($el->parentNode && strtolower($el->parentNode->nodeName) === 'label');

            if (! $labelled && $el->getAttribute('id') !== '') {
                $query = sprintf('//label[@for=%s]', json_encode($el->getAttribute('id')));
                $labelled = $xp->query($query)->length > 0;
            }

            $this->assertTrue(
                $labelled,
                "The {$el->nodeName} named \"{$name}\" on {$context} has no programmatic label."
            );
        }
    }

    private function assertAriaReferencesResolve(string $html, string $context): void
    {
        $xp = $this->dom($html);
        $ids = $this->idCounts($xp);

        foreach ($xp->query('//*[@aria-describedby] | //*[@aria-labelledby]') as $el) {
            foreach (['aria-describedby', 'aria-labelledby'] as $attr) {
                $value = $el->getAttribute($attr);

                if ($value === '') {
                    continue;
                }

                foreach (preg_split('/\s+/', $value) as $ref) {
                    if ($ref !== '') {
                        $this->assertArrayHasKey(
                            $ref,
                            $ids,
                            "{$attr}=\"{$ref}\" on {$context} points at an id that is not in the document."
                        );
                    }
                }
            }
        }
    }

    private function assertTableHeadersAreScoped(string $html, string $context): void
    {
        $xp = $this->dom($html);

        foreach ($xp->query('//th') as $th) {
            $this->assertTrue(
                $th->hasAttribute('scope') || $th->hasAttribute('headers'),
                'A <th> on '.$context.' has no scope attribute, so its role as a column or row '
                    .'header is ambiguous to a screen reader.'
            );
        }
    }

    /* ------------------------------------------------------------------ */

    public function test_public_pages_have_no_duplicate_ids(): void
    {
        foreach ($this->publicPages() as $path) {
            $this->assertNoDuplicateIds($this->get($path)->getContent(), $path);
        }
    }

    public function test_public_pages_label_every_control(): void
    {
        foreach ($this->publicPages() as $path) {
            $this->assertEveryControlIsLabelled($this->get($path)->getContent(), $path);
        }
    }

    /**
     * The defect that started this: one form per template, all sharing ids.
     */
    public function test_the_slot_template_page_has_no_duplicate_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->create([
            'code' => 'A11Y-TPL',
            'service_window_id' => ServiceWindow::factory()->create()->id,
        ]);

        // Several templates, because the bug only appears with more than one.
        foreach (range(1, 3) as $i) {
            SlotTemplate::create([
                'service_id' => $service->id,
                'day_of_week' => $i,
                'start_time' => '08:00:00',
                'end_time' => '12:00:00',
                'slot_minutes' => 30,
                'capacity' => 5,
                'effective_from' => today()->toDateString(),
                'is_active' => true,
            ]);
        }

        $content = $this->actingAs($admin)
            ->get(route('admin.templates.index', $service))
            ->assertOk()
            ->getContent();

        // Four forms on the page, each with the same field names.
        $this->assertStringContainsString('tpl-', $content);

        $this->assertNoDuplicateIds($content, 'the slot template page');
        $this->assertEveryControlIsLabelled($content, 'the slot template page');
        $this->assertAriaReferencesResolve($content, 'the slot template page');
    }

    /**
     * Each template's form shows its own values after a rejected save.
     *
     * old() is global, not per-form, so with the repopulate left on, a failed save
     * on the "add" form would refill every existing template's edit form with the
     * rejected input - as if an administrator had typed it into each of them.
     */
    public function test_a_rejected_template_save_does_not_overwrite_the_other_forms(): void
    {
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->create([
            'code' => 'A11Y-OLD',
            'service_window_id' => ServiceWindow::factory()->create()->id,
        ]);

        $existing = SlotTemplate::create([
            'service_id' => $service->id,
            'day_of_week' => 3,
            'start_time' => '13:00:00',
            'end_time' => '16:00:00',
            'slot_minutes' => 45,
            'capacity' => 7,
            'effective_from' => today()->toDateString(),
            'is_active' => true,
        ]);

        // A rejected create: the times are inverted.
        $this->actingAs($admin)
            ->from(route('admin.templates.index', $service))
            ->post("/admin/services/{$service->code}/templates", [
                'day_of_week' => 5,
                'start_time' => '17:00',
                'end_time' => '09:00',
                'slot_minutes' => 20,
                'capacity' => 99,
                'effective_from' => today()->toDateString(),
                'is_active' => '1',
            ])
            ->assertSessionHas('error');

        $content = $this->actingAs($admin)
            ->get(route('admin.templates.index', $service))
            ->getContent();

        $xp = $this->dom($content);

        $valueOf = function (string $id) use ($xp): ?string {
            $nodes = $xp->query(sprintf('//*[@id=%s]', json_encode($id)));

            return $nodes->length > 0 ? $nodes->item(0)->getAttribute('value') : null;
        };

        // The add form is the one that should repopulate with the rejected input.
        $this->assertSame('99', $valueOf('capacity'));

        // The existing template must still show its own stored values.
        $this->assertSame(
            '7',
            $valueOf('tpl-'.$existing->id.'-capacity'),
            "The existing template form was refilled from another form's rejected input."
        );

        // And its own opening time, not the rejected 17:00. Compared against the
        // stored value, since a TIME column renders as HH:MM:SS.
        $this->assertSame($existing->start_time, $valueOf('tpl-'.$existing->id.'-start'));
        $this->assertNotSame('17:00', $valueOf('tpl-'.$existing->id.'-start'));
    }

    public function test_the_slot_template_page_keeps_each_form_distinct(): void
    {
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->create([
            'code' => 'A11Y-DISTINCT',
            'service_window_id' => ServiceWindow::factory()->create()->id,
        ]);

        $template = SlotTemplate::create([
            'service_id' => $service->id,
            'day_of_week' => 2,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'slot_minutes' => 30,
            'capacity' => 5,
            'effective_from' => today()->toDateString(),
            'is_active' => true,
        ]);

        $content = $this->actingAs($admin)
            ->get(route('admin.templates.index', $service))
            ->getContent();

        // The per-template ids must include the template's own id, otherwise two
        // templates would collide again.
        $this->assertStringContainsString('id="tpl-'.$template->id.'-start"', $content);
        $this->assertStringContainsString('id="tpl-'.$template->id.'-end"', $content);
        $this->assertStringContainsString('id="tpl-'.$template->id.'-active"', $content);
    }

    public function test_admin_tables_scope_their_headers(): void
    {
        $admin = User::factory()->admin()->create();

        ServiceWindow::factory()->create(['code' => 'A11Y-WIN']);
        Service::factory()->create([
            'code' => 'A11Y-SVC',
            'service_window_id' => ServiceWindow::first()->id,
        ]);

        foreach ([
            '/admin/windows',
            '/admin/services',
            '/admin/staff',
            '/admin/slots',
        ] as $path) {
            $this->assertTableHeadersAreScoped(
                $this->actingAs($admin)->get($path)->assertOk()->getContent(),
                $path
            );
        }
    }

    public function test_the_client_appointment_history_scopes_its_headers(): void
    {
        $client = User::factory()->client()->create();

        $content = $this->actingAs($client)
            ->get('/my-appointments')
            ->assertOk()
            ->getContent();

        $this->assertTableHeadersAreScoped($content, '/my-appointments');
    }

    public function test_every_page_declares_a_language_and_a_main_heading(): void
    {
        // The test database is empty, so the service detail page needs a real
        // service to render rather than relying on seeded data.
        Service::factory()->create([
            'code' => 'A11Y-DETAIL',
            'service_window_id' => ServiceWindow::factory()->create()->id,
        ]);

        $paths = array_merge($this->publicPages(), ['/services/A11Y-DETAIL']);

        foreach ($paths as $path) {
            $xp = $this->dom($this->get($path)->assertOk()->getContent());

            $this->assertSame(
                1,
                $xp->query('//html[@lang]')->length,
                "{$path} has no lang attribute on <html>."
            );

            $this->assertGreaterThan(
                0,
                $xp->query('//h1')->length,
                "{$path} has no <h1>, so the page has no top-level heading."
            );
        }
    }

    public function test_heading_levels_do_not_skip(): void
    {
        $client = User::factory()->client()->create();
        $admin = User::factory()->admin()->create();

        $paths = [
            '/' => null,
            '/services' => null,
            '/login' => null,
            '/my-appointments' => $client,
            '/admin' => $admin,
            '/admin/services' => $admin,
            '/admin/staff' => $admin,
        ];

        foreach ($paths as $path => $user) {
            $response = $user ? $this->actingAs($user) : $this;

            $xp = $this->dom($response->get($path)->assertOk()->getContent());

            $levels = [];

            foreach ($xp->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
                $levels[] = (int) substr($heading->nodeName, 1);
            }

            $previous = null;

            foreach ($levels as $level) {
                if ($previous !== null) {
                    $this->assertLessThanOrEqual(
                        $previous + 1,
                        $level,
                        "{$path} jumps from h{$previous} to h{$level}, which breaks the heading outline."
                    );
                }

                $previous = $level;
            }
        }
    }

    public function test_admin_forms_report_errors_to_assistive_technology(): void
    {
        $admin = User::factory()->admin()->create();

        // Submit something invalid, then check the re-rendered page announces it.
        $this->actingAs($admin)
            ->from(route('admin.windows.create'))
            ->post('/admin/windows', ['name' => '', 'code' => 'lower case', 'avg_service_minutes' => 0])
            ->assertSessionHasErrors(['name', 'code', 'avg_service_minutes']);

        $content = $this->actingAs($admin)
            ->get(route('admin.windows.create'))
            ->getContent();

        // Validation messages carry role="alert" so they are announced when they
        // appear after a submit.
        $this->assertStringContainsString('role="alert"', $content);

        $xp = $this->dom($content);

        foreach ($xp->query('//input[aria-invalid="true"]') as $el) {
            $this->assertNotSame('', $el->getAttribute('aria-invalid'));
        }
    }
}
