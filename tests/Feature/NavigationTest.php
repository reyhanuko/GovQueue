<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The main navigation.
 *
 * Two problems were fixed here, both found by looking at the navbar rather than
 * at the code:
 *
 *  - The primary links were `hidden md:flex` with no mobile replacement. Below
 *    768px there was no navigation at all, so anyone on a phone could only reach
 *    Home, Services, or the Queue Display by typing the URL. For a municipal
 *    service where plenty of clients are on a phone, that is not acceptable.
 *  - An administrator had no link to the staff console, because the nav used
 *    `@elseif` and the admin branch came first - even though administrators are
 *    explicitly allowed to open any window's console.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function guestPage(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    public function test_the_navbar_offers_a_disclosure_menu_for_small_screens(): void
    {
        $content = $this->guestPage();

        // The <details> disclosure is what replaced the missing mobile nav. It
        // needs no JavaScript and stays keyboard operable.
        $this->assertStringContainsString(
            '<details',
            $content,
            'No disclosure menu in the navbar, so small screens have no navigation.'
        );

        $this->assertStringContainsString('Open main menu', $content);
    }

    public function test_the_mobile_menu_lists_the_public_destinations(): void
    {
        $content = $this->guestPage();

        foreach (['Services', 'Queue Display'] as $label) {
            $this->assertStringContainsString($label, $content);
        }
    }

    public function test_a_client_gets_their_own_links_in_the_mobile_menu(): void
    {
        $content = $this->actingAs(User::factory()->client()->create())
            ->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('My Appointments', $content);
        $this->assertStringContainsString('Profile', $content);
    }

    public function test_a_client_has_no_administration_link(): void
    {
        $content = $this->actingAs(User::factory()->client()->create())
            ->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('>Administration<', $content);
    }

    public function test_an_administrator_is_offered_the_staff_console(): void
    {
        // Admins may open any window's console, so the link belongs in their
        // branch too - not only under the staff branch that the elseif skipped.
        $content = $this->actingAs(User::factory()->admin()->create())
            ->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Administration', $content);
        $this->assertStringContainsString(route('staff.dashboard'), $content);
    }

    public function test_the_navbar_says_where_you_are(): void
    {
        /*
         * No menu in the app said which of its links you were already on. Two
         * signals rather than one: aria-current for assistive technology, and a
         * visible change for everyone else - colour alone does not survive a
         * greyscale print or a colour-blind reader.
         *
         * Matching is by route name, so query strings and trailing slashes
         * cannot knock the highlight out.
         */
        foreach (['/' => 'Home', '/services' => 'Services'] as $path => $label) {
            $content = $this->get($path)->assertOk()->getContent();

            $this->assertContains(
                $label,
                $this->currentLabels($content),
                "Nothing in the navbar marks {$label} as the current page on {$path}."
            );
        }
    }

    /**
     * The text of every element marked as the current page.
     *
     * @return array<int, string>
     */
    private function currentLabels(string $content): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($content);
        libxml_clear_errors();

        $xp = new \DOMXPath($dom);

        $current = [];

        foreach ($xp->query('//*[@aria-current="page"]') as $node) {
            $current[] = trim(preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $current;
    }

    /**
     * The highlight has to survive the screens underneath a section.
     *
     * It did not, in two places at once.
     *
     * The booking screens live at /services/{code}/book but are named
     * client.booking.* - the booking belongs to the client, not to the
     * catalogue - so matching "services.*" left the whole booking flow with no
     * highlight at all. That is the one place a client is most likely to be,
     * and the one place they most need to know they are still in Services.
     *
     * The administrator's sidebar matched each link's index route exactly, so
     * opening the form to add a service lit nothing: the sidebar gave no hint
     * that Services was still where you were.
     */
    public function test_the_highlight_survives_a_screen_underneath_the_section(): void
    {
        $client = User::factory()->client()->create();
        $service = Service::factory()->create(['code' => 'CERT-IND', 'is_active' => true]);

        $booking = $this->actingAs($client)
            ->get('/services/CERT-IND/book')
            ->assertOk()
            ->getContent();

        $this->assertContains(
            'Services',
            $this->currentLabels($booking),
            'The booking screen is under Services, and the highlight is gone.'
        );

        $admin = User::factory()->admin()->create();

        foreach ([
            '/admin/services/create' => 'Services',
            '/admin/windows/create' => 'Windows',
        ] as $path => $label) {
            $content = $this->actingAs($admin)->get($path)->assertOk()->getContent();

            $this->assertContains(
                $label,
                $this->currentLabels($content),
                "The administrator sidebar does not mark {$label} as the current section on {$path}."
            );
        }
    }

    /**
     * The link has to name the screen the person is going to.
     *
     * Every one of these said "Go to my dashboard" and pointed at the
     * /dashboard route, which redirects by role. So a member of staff was sent
     * to the queue console by a link that called it a dashboard, and a client
     * was sent to their appointments by the same one. The destination was
     * right; the name was not, and a name is what a person reads before they
     * click.
     */
    public function test_the_home_link_names_the_screen_each_role_actually_has(): void
    {
        $window = ServiceWindow::factory()->create();

        foreach ([
            ['client', 'Go to my appointments', 'client.appointments.index', []],
            ['staff', 'Go to my staff console', 'staff.dashboard', ['service_window_id' => $window->id]],
            ['admin', 'Go to my dashboard', 'admin.dashboard', []],
        ] as [$role, $label, $route, $overrides]) {
            $user = User::factory()->{$role}()->create($overrides);

            $content = $this->actingAs($user)->get('/')->assertOk()->getContent();

            $this->assertStringContainsString(
                $label,
                $content,
                "The home page tells a {$role} to \"{$label}\"."
            );

            $this->assertStringContainsString(
                route($route),
                $content,
                "The home page sends a {$role} somewhere other than their own screen."
            );
        }
    }

    /**
     * A visitor with no account has no dashboard either.
     *
     * The error pages are the ones a signed-out visitor can reach, and they
     * offered a dashboard they do not have - which for them meant a bounce
     * through the sign-in form on the way to a page they could have read.
     */
    public function test_a_visitor_with_no_account_is_offered_the_page_they_have(): void
    {
        $html = $this->blade('<x-user-home-link />');

        $this->assertStringContainsString('Go to the home page', $html);
        $this->assertStringContainsString(route('home'), $html);
    }

    public function test_every_signed_in_role_is_offered_their_inbox(): void
    {
        // One inbox for every role; what is in it differs by role, but the door
        // to it is in the same place.
        $window = ServiceWindow::factory()->create();

        foreach ([
            User::factory()->client()->create(),
            User::factory()->staff()->create(['service_window_id' => $window->id]),
            User::factory()->admin()->create(),
        ] as $user) {
            $this->actingAs($user)
                ->get(route('home'))
                ->assertOk()
                ->assertSee(route('notifications.index'), escape: false);
        }
    }

    public function test_the_public_pages_all_carry_the_navigation(): void
    {
        // The navbar is part of the layout, so a missing navigation on any public
        // page is a bug worth catching.
        foreach (['/', '/services'] as $path) {
            $content = $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString(
                route('services.index'),
                $content,
                "The navbar is missing its Services link on {$path}."
            );
        }
    }

    public function test_the_lobby_display_deliberately_has_no_navigation(): void
    {
        // A wall-mounted board has no one to click a menu, so the full-bleed dark
        // layout omits the navbar on purpose. Asserted so the omission is a
        // decision rather than an accident.
        $content = $this->get('/queue-display')->assertOk()->getContent();

        $this->assertStringNotContainsString(route('services.index'), $content);
    }

    /**
     * Both layouts request the same typeface weights.
     *
     * They used to request different subsets: the app layout asked for
     * 400-800 while the views use font-black (900), so those headings silently
     * rendered at 800; the display asked for 400/600/800/900 while also using
     * font-medium and font-bold. Same brand, two lists, so text looked different
     * per screen.
     */
    public function test_both_layouts_load_the_same_font_weights(): void
    {
        $pattern = '/fonts\.bunny\.net\/css\?family=figtree:([\d,]+)/';

        $app = $this->get('/')->assertOk()->getContent();
        $display = $this->get('/queue-display')->assertOk()->getContent();

        $this->assertSame(1, preg_match($pattern, $app, $appMatch), 'App layout font link not found.');
        $this->assertSame(1, preg_match($pattern, $display, $displayMatch), 'Display font link not found.');

        $this->assertSame(
            $appMatch[1],
            $displayMatch[1],
            'The two layouts request different font weights, so type renders differently per screen.'
        );

        // Every weight the markup actually uses has to be available.
        foreach ([400, 500, 600, 700, 900] as $weight) {
            $this->assertContains(
                $weight,
                array_map('intval', explode(',', $appMatch[1])),
                "Weight {$weight} is used in the markup but not requested by the layout."
            );
        }
    }
}
