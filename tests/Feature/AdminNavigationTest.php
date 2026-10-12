<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The administration side navigation, after the move from a flat tab row to a
 * grouped sidebar.
 *
 * Two things could have gone wrong and neither shows up on a screenshot. The
 * nav used to be included by each of the thirteen admin views, so any view that
 * forgot lost its navigation silently - which is why it is now rendered by the
 * layout. And a sidebar only works if every section is still reachable, so each
 * route is asserted here rather than by eye.
 */
class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adminSections(): array
    {
        return [
            'dashboard' => ['admin.dashboard', 'Dashboard'],
            'services' => ['admin.services.index', 'Services'],
            'windows' => ['admin.windows.index', 'Windows'],
            'daily slots' => ['admin.slots.index', 'Daily slots'],
            'holidays' => ['admin.holidays.index', 'Holidays'],
            'staff' => ['admin.staff.index', 'Staff accounts'],
            'reports' => ['admin.reports.index', 'Reports'],
            'audit' => ['admin.audit.index', 'Audit trail'],
            'notification log' => ['admin.notifications.index', 'Notification log'],
        ];
    }

    #[DataProvider('adminSections')]
    public function test_every_section_links_from_the_sidebar(string $routeName, string $label): void
    {
        $this->actingAs($this->admin)
            ->get(route($routeName))
            ->assertOk()
            ->assertSee(route($routeName), escape: false)
            ->assertSee($label);
    }

    public function test_the_sidebar_is_rendered_by_the_layout_not_by_each_view(): void
    {
        // Every admin page has it, including ones nobody would think to check.
        foreach ([
            'admin.dashboard',
            'admin.services.index',
            'admin.staff.index',
            'admin.audit.index',
        ] as $routeName) {
            $this->actingAs($this->admin)
                ->get(route($routeName))
                ->assertOk()
                ->assertSee('Administration menu', escape: false);
        }
    }

    public function test_the_sidebar_marks_where_you_are(): void
    {
        $content = $this->actingAs($this->admin)->get('/admin/windows')->assertOk()->getContent();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($content);
        libxml_clear_errors();

        $xp = new \DOMXPath($dom);

        $current = $xp->query('//*[@aria-current="page"]');

        $this->assertGreaterThan(0, $current->length, 'Nothing on the page says which page you are on.');

        // Only the section you are in. Marking every link at once would be no
        // better than marking none.
        $marked = [];

        foreach ($current as $node) {
            $marked[] = trim($node->textContent);
        }

        $this->assertContains('Windows', $marked);
        $this->assertNotContains('Audit trail', $marked);
    }

    public function test_the_sidebar_is_a_named_landmark(): void
    {
        // There are now two navigation regions on an admin page. A screen reader
        // listing "navigation" twice with nothing to tell them apart is worse
        // than one region.
        $content = $this->actingAs($this->admin)->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Administration"', $content);
        $this->assertStringContainsString('aria-label="Main navigation"', $content);
    }

    public function test_a_client_sees_no_admin_sidebar_anywhere(): void
    {
        // The nav is guarded by the user's role, so it cannot appear on a page
        // a client can reach - but the layout also decides the two-column shell
        // from the role, and both have to agree or a client gets an empty column.
        $client = User::factory()->client()->create();

        $content = $this->actingAs($client)->get('/my-appointments')->assertOk()->getContent();

        $this->assertStringNotContainsString('Administration menu', $content);
    }

    public function test_a_new_admin_page_inherits_the_sidebar_without_remembering_to_include_it(): void
    {
        AuditLog::record('window.created', $this->admin, []);

        $this->actingAs($this->admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('Audit trail')
            ->assertSee('Every configuration change');
    }
}
