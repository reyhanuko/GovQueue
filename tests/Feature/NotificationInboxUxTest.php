<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\OpsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The inbox screen's own affordances: the unread filter, the total count, and
 * the remove control's hit area.
 *
 * NotificationInboxTest covers who can see what. This covers whether the screen
 * is usable once you are on it.
 */
class NotificationInboxUxTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
    }

    private function send(int $count, string $title = 'Queue alert'): void
    {
        for ($i = 0; $i < $count; $i++) {
            Notification::send($this->staff, new OpsNotification(
                title: $title,
                summary: 'Something changed.',
                url: route('staff.dashboard'),
                level: 'info',
            ));
        }
    }

    public function test_the_unread_filter_hides_read_messages(): void
    {
        $this->send(3);

        // One of them read.
        $this->staff->notifications()->latest()->first()->markAsRead();

        $html = $this->actingAs($this->staff)
            ->get('/messages?unread=1')
            ->assertOk()
            ->getContent();

        // Two unread rows remain. Counted by the remove control's aria-label,
        // which occurs exactly once per row - the title itself appears several
        // times per row (heading, link text, delete label, confirm payload).
        $this->assertSame(2, substr_count($html, 'aria-label="Remove this message'));
    }

    public function test_the_unfiltered_list_shows_everything(): void
    {
        $this->send(3);
        $this->staff->notifications()->latest()->first()->markAsRead();

        $this->actingAs($this->staff)
            ->get('/messages')
            ->assertOk()
            ->assertSee('Queue alert');
    }

    /**
     * The denominator has to stay the whole inbox. Computed from the filtered
     * query it would always read "2 of 2" and tell nobody anything.
     */
    public function test_the_count_reports_the_whole_inbox_while_filtered(): void
    {
        $this->send(30);
        $this->staff->notifications()->get()->last()->markAsRead();

        $this->actingAs($this->staff)
            ->get('/messages?unread=1')
            ->assertOk()
            ->assertSee('unread of', escape: false)
            ->assertSee('30 messages', escape: false);
    }

    public function test_a_filtered_inbox_with_nothing_unread_does_not_say_it_is_empty(): void
    {
        $this->send(2);
        $this->staff->notifications()->get()->each->markAsRead();

        // "No messages yet" would be a lie here: the inbox holds two.
        $this->actingAs($this->staff)
            ->get('/messages?unread=1')
            ->assertOk()
            ->assertSee('Nothing unread')
            ->assertDontSee('No messages yet');
    }

    /**
     * Regression: this was a "&times;" character at px-2 py-1.5 text-xs, roughly
     * a 16px target against WCAG 2.2's 24px minimum, wrapped around a permanent
     * delete. The identical defect was fixed for the toast two commits earlier
     * and left here.
     */
    public function test_the_remove_control_meets_the_minimum_target_size(): void
    {
        $this->send(1);

        $html = $this->actingAs($this->staff)->get('/messages')->assertOk()->getContent();

        // The submit button that deletes, not any button on the page.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*\b(h-9|h-10|h-11|h-12)\b[^>]*\bw-(9|10|11|12)\b[^>]*aria-label="Remove this message/s',
            $html,
            'The remove control must be at least 24px in both dimensions.',
        );

        // And it must not be a bare times character.
        $this->assertStringNotContainsString('&times;', $html);
    }

    public function test_the_timestamp_carries_an_absolute_time(): void
    {
        $this->send(1);

        $this->actingAs($this->staff)
            ->get('/messages')
            ->assertOk()
            ->assertSee('<time datetime=', escape: false);
    }

    /**
     * "Open" for unread and "View" for read described the same link with two
     * names, so a reader had to learn that they meant the same thing.
     */
    /**
     * Regression, and the reason this file exists in this form.
     *
     * The count line read `... in total@if ($unreadCount > 0), N unread@endif`.
     * Blade matches directives with \B@, so a directive whose `@` directly
     * follows a word character is at a word boundary, fails the regex, and is
     * never compiled - it reaches the browser as literal text. The page rendered
     * "20 messages in total@if ($unreadCount > 0), 14 unread." to every reader.
     *
     * Asserted on the output rather than the source, because the source is what
     * looked fine.
     */
    public function test_no_uncompiled_blade_directive_reaches_the_page(): void
    {
        $this->send(20);
        $this->staff->notifications()->get()->take(6)->each->markAsRead();

        foreach (['/messages', '/messages?unread=1'] as $url) {
            $content = $this->actingAs($this->staff)->get($url)->assertOk()->getContent();

            foreach (['@if', '@endif', '@else', '@foreach', '@endforeach', '@php', '{{'] as $directive) {
                $this->assertStringNotContainsString(
                    $directive,
                    $content,
                    "An uncompiled Blade directive ({$directive}) was rendered on {$url}.",
                );
            }
        }
    }

    public function test_the_unfiltered_count_reads_as_a_sentence(): void
    {
        $this->send(20);
        $this->staff->notifications()->get()->take(6)->each->markAsRead();

        $this->actingAs($this->staff)
            ->get('/messages')
            ->assertOk()
            ->assertSee('20 messages in total.')
            ->assertSee('14 unread.');
    }

    public function test_the_count_is_not_a_live_region(): void
    {
        $this->send(3);

        $content = $this->actingAs($this->staff)->get('/messages')->assertOk()->getContent();

        // F-22 established that duplicated role="status" regions made a screen
        // reader announce the same sentence twice. This one re-fires after the
        // filter navigation and has nothing to announce at load time.
        $this->assertStringNotContainsString('role="status"', $content);
    }

    /**
     * The message list only.
     *
     * The navbar bell previews the newest unread messages on every page,
     * including the inbox, so a whole-page assertion would find a title in the
     * dropdown even when the list itself is correctly filtered. Slicing from
     * where the list starts is what makes "this is not in the list" mean it.
     */
    private function listRegion(string $html): string
    {
        $start = strpos($html, 'mt-6 space-y-2');

        return $start === false ? '' : substr($html, $start);
    }

    /**
     * The bell is a disclosure, so the newest unread messages are readable without
     * leaving the page - the console or the booking screen somebody is mid-way
     * through.
     */
    public function test_the_bell_previews_the_newest_unread(): void
    {
        $this->send(1, 'Oldest unread');
        $this->send(1, 'Newest unread');

        $content = $this->actingAs($this->staff)->get('/staff')->assertOk()->getContent();

        $this->assertStringContainsString('Newest unread', $content);
    }

    /**
     * Read messages are left out. The badge is about what still needs attention,
     * and mixing in what has been dealt with is what makes a preview longer
     * than the badge can be trusted to summarise.
     */
    public function test_the_bell_preview_excludes_read_messages(): void
    {
        $this->send(3);
        $this->staff->notifications()->get()->each->markAsRead();

        $content = $this->actingAs($this->staff)->get('/staff')->assertOk()->getContent();

        $this->assertStringContainsString('Nothing unread.', $content);
    }

    /**
     * Without script the disclosure never opens, so a real link to the inbox has
     * to exist inside it - a control that only works with script is a dead end
     * for somebody with script disabled.
     */
    public function test_the_dropdown_keeps_a_real_link_to_the_inbox(): void
    {
        $this->send(1);

        $content = $this->actingAs($this->staff)->get('/staff')->assertOk()->getContent();

        $this->assertStringContainsString('View all messages', $content);
        $this->assertStringContainsString(route('notifications.index'), $content);
    }

    public function test_severity_filter_is_offered_to_the_back_office(): void
    {
        $this->send(1);

        $content = $this->actingAs($this->staff)->get('/messages')->assertOk()->getContent();

        $this->assertStringContainsString('Severity', $content);
        $this->assertStringContainsString('Critical', $content);
    }

    /**
     * A client message has no severity, so offering the filter would offer three
     * choices that all return nothing.
     */
    public function test_severity_filter_is_not_offered_to_a_client(): void
    {
        $client = User::factory()->client()->create();

        $content = $this->actingAs($client)->get('/messages')->assertOk()->getContent();

        $this->assertStringNotContainsString('>Severity<', $content);
    }

    public function test_the_severity_filter_narrows_the_list(): void
    {
        Notification::send($this->staff, new OpsNotification(
            title: 'Queue display stopped updating',
            summary: 'Realtime updates are failing.',
            url: route('staff.dashboard'),
            level: 'critical',
        ));
        $this->send(2, 'Routine update');

        $content = $this->actingAs($this->staff)
            ->get('/messages?level=critical')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Queue display stopped updating', $content);
        $this->assertStringNotContainsString('Routine update', $this->listRegion($content));
    }

    public function test_an_unknown_severity_is_ignored_rather_than_erroring(): void
    {
        $this->send(1);

        $this->actingAs($this->staff)->get('/messages?level=nonsense')->assertOk();
    }

    /**
     * Critical and warning sort above the routine, because the first question on
     * opening the inbox is whether anything needs acting on.
     */
    public function test_critical_alerts_sort_above_routine_messages(): void
    {
        $this->send(2, 'Routine one');
        $this->send(1, 'Routine two');

        Notification::send($this->staff, new OpsNotification(
            title: 'Queue display stopped updating',
            summary: 'Realtime updates are failing.',
            url: route('staff.dashboard'),
            level: 'critical',
        ));

        $content = $this->actingAs($this->staff)->get('/messages')->assertOk()->getContent();
        $list = $this->listRegion($content);

        $criticalAt = strpos($list, 'Queue display stopped updating');
        $routineAt = strpos($list, 'Routine one');

        $this->assertNotFalse($criticalAt, 'The critical alert is not in the list.');
        $this->assertNotFalse($routineAt, 'The routine message is not in the list.');
        $this->assertLessThan(
            $routineAt,
            $criticalAt,
            'A critical alert must sort above routine messages.',
        );

        $this->assertStringContainsString('Needs attention', $content);
    }

    /**
     * The count must cover every page, not the one being viewed.
     *
     * "Needs attention (25)" when thirty alerts need attention is the same mistake as
     * a total taken from the filtered query: it reads as a whole number and is not
     * one.
     */
    public function test_the_attention_count_covers_every_page(): void
    {
        // 30 criticals - one more than a page - plus routine noise after them.
        for ($i = 0; $i < 30; $i++) {
            Notification::send($this->staff, new OpsNotification(
                title: 'Queue display stopped updating',
                summary: 'Realtime updates are failing.',
                url: route('staff.dashboard'),
                level: 'critical',
            ));
        }

        $this->send(2, 'Routine update');

        $content = $this->actingAs($this->staff)->get('/messages')->assertOk()->getContent();

        $this->assertStringContainsString('Needs attention (30)', $content);
    }

    public function test_the_row_action_has_one_label(): void
    {
        $this->send(2);
        $this->staff->notifications()->latest()->first()->markAsRead();

        $html = $this->actingAs($this->staff)->get('/messages')->assertOk()->getContent();

        // Both rows offer "Open", read or unread.
        $this->assertSame(2, substr_count($html, 'aria-label="Remove this message'));
        $this->assertStringContainsString('Open', $html);

        // Every "View" on the page must belong to the nav's "View all
        // messages" link. A row still saying "View" would show up as a surplus.
        $this->assertSame(
            substr_count($html, 'View all messages'),
            substr_count($html, 'View'),
            'A row still says "View"; the two labels describe the same link.',
        );
    }
}
