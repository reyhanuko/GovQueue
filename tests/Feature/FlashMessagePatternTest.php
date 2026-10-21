<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One message, one place, decided by what kind of message it is.
 *
 * Every action used to render its success twice - as a top-right toast and as an
 * inline banner, with identical wording. Visually that was clutter. The part
 * that was not cosmetic is that both carried role="status", so a screen reader
 * announced the same sentence twice on every successful action.
 *
 * The old justification was that the inline copy covered the case where the
 * overlay failed to render. That case does not exist: x-show hides the toast by
 * adding a style attribute, so with no JavaScript the toast renders normally
 * and simply never dismisses. The duplicate was guarding nothing.
 *
 * The rule these tests hold:
 *   success  toast   - a confirmation, and visible from anywhere on the page
 *   error    inline  - something to act on, which must not expire unread
 *   warning  inline  - same, stronger, and never dismisses
 */
class FlashMessagePatternTest extends TestCase
{
    use RefreshDatabase;

    /** The rendered page, with the session flash set. */
    private function renderWith(array $flash): string
    {
        foreach ($flash as $key => $value) {
            session()->flash($key, $value);
        }

        return $this->get(route('home'))->assertOk()->getContent();
    }

    /* ------------------------------------------------------------------ */
    /* Success */
    /* ------------------------------------------------------------------ */

    public function test_a_success_is_shown_once(): void
    {
        $html = $this->renderWith(['success' => 'Appointment cancelled.']);

        $this->assertSame(
            1,
            substr_count($html, 'Appointment cancelled.'),
            'The success message is rendered more than once. It used to be a toast '
                .'and an inline banner, which meant a screen reader announced it twice.',
        );
    }

    public function test_a_success_is_shown_in_a_toast(): void
    {
        $html = $this->renderWith(['success' => 'Appointment confirmed.']);

        // The toast is the fixed overlay, which is what makes it visible to
        // somebody who just acted at the bottom of a long table.
        $this->assertStringContainsString('fixed right-4 top-4', $html);
        $this->assertStringContainsString('Appointment confirmed.', $html);
    }

    public function test_a_success_toast_can_be_dismissed(): void
    {
        $html = $this->renderWith(['success' => 'Appointment confirmed.']);

        // 8 seconds is short for somebody still reading it.
        $this->assertStringContainsString('aria-label="Dismiss this message"', $html);
    }

    public function test_the_laravel_status_convention_is_also_shown_once(): void
    {
        $html = $this->renderWith(['status' => 'We have emailed you a reset link.']);

        $this->assertSame(1, substr_count($html, 'We have emailed you a reset link.'));
    }

    /* ------------------------------------------------------------------ */
    /* Errors */
    /* ------------------------------------------------------------------ */

    public function test_an_error_is_not_a_toast(): void
    {
        $html = $this->renderWith(['error' => 'That slot has just been taken.']);

        // A refusal is something to act on. A 12-second timer expires before
        // somebody who was interrupted has finished reading it.
        $this->assertStringContainsString('That slot has just been taken.', $html);
        $this->assertStringNotContainsString(
            'setTimeout(() => show = false, 12000)',
            $html,
            'An error is still auto-dismissing. It has to be readable at the pace '
                .'somebody actually reads it.',
        );
    }

    public function test_an_error_sits_in_the_page_flow(): void
    {
        $html = $this->renderWith(['error' => 'That slot has just been taken.']);

        $this->assertMatchesRegularExpression(
            '/<div role="alert"[^>]*class="[^"]*mb-6[^"]*"[^>]*>\s*That slot has just been taken/s',
            $html,
            'The error is not inline. It should be in the reading order, not in a corner.',
        );
    }

    public function test_an_error_still_carries_its_hint(): void
    {
        $html = $this->renderWith([
            'error' => 'Cannot delete that service.',
            'hint' => '34 appointments reference it. Deactivate it instead.',
        ]);

        $this->assertStringContainsString('34 appointments reference it. Deactivate it instead.', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Warnings */
    /* ------------------------------------------------------------------ */

    public function test_a_warning_is_inline_and_never_dismisses(): void
    {
        $html = $this->renderWith(['warning' => 'The window is oversubscribed.']);

        $this->assertStringContainsString('Heads up', $html);
        $this->assertStringNotContainsString('setTimeout', substr($html, strpos($html, 'Heads up') ?: 0, 400));
    }

    /**
     * The two together are the point.
     *
     * Asserted as a pair because either one alone would pass while the other
     * regressed, and the duplication is precisely a thing where one of the two
     * comes back unnoticed.
     */
    public function test_a_page_never_shows_two_live_regions_carrying_the_same_text(): void
    {
        foreach (['success' => 'Saved.', 'status' => 'Done.', 'error' => 'Refused.'] as $key => $text) {
            $html = $this->renderWith([$key => $text]);

            // Counted as raw text, not as ">text": the success toast wraps its
            // message in a span beside the dismiss button, so the markup around
            // it is not a reliable thing to match on. The duplication that
            // matters is the message appearing twice, whatever wraps it.
            $this->assertSame(
                1,
                substr_count($html, $text),
                "The \"{$key}\" message is rendered more than once on the page.",
            );
        }
    }
}
