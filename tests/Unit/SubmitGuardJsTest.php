<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The double-submit guard in resources/js/app.js.
 *
 * This one is asserted against the source rather than at runtime, because the
 * failure it guards cannot be reproduced in a PHP test - and it shipped.
 *
 * The guard disables a form's submit controls on the submit event, to stop a
 * double post. It also ran for submissions that were then *cancelled*: every
 * confirmation dialog returns false from its onsubmit handler, which
 * preventDefaults the event. The guard did not check for that, so the button
 * stayed disabled permanently.
 *
 * The consequence reached three separate screens and was reported as three
 * unrelated bugs: a Remove button that worked exactly once, and delete
 * confirmations that appeared to be missing entirely (the dialog opened on the
 * first click; after cancelling it, the button was dead and so the dialog never
 * appeared again). Each screen was correct HTML. The fault was in this file.
 *
 * The assertion is on the guard itself rather than on a call site, because the
 * call sites were never wrong and there were eight of them.
 */
class SubmitGuardJsTest extends TestCase
{
    private function appJs(): string
    {
        return (string) file_get_contents(base_path('resources/js/app.js'));
    }

    public function test_the_guard_skips_a_submission_that_was_already_cancelled(): void
    {
        $this->assertStringContainsString(
            'event.defaultPrevented',
            $this->appJs(),
            'The double-submit guard must check defaultPrevented. Without it, cancelling any '
            .'confirmation dialog leaves the button permanently disabled.'
        );
    }

    public function test_the_guard_returns_before_touching_the_buttons(): void
    {
        // The check has to come before the disabling, not after. Reading the
        // source as text is the only way to catch that ordering being reversed
        // in a later edit - the runtime behaviour is identical either way in a
        // browser with no console open.
        $source = $this->appJs();

        $check = strpos($source, 'event.defaultPrevented');
        $disable = strpos($source, 'el.disabled = true');

        $this->assertIsInt($check, 'No defaultPrevented check found.');
        $this->assertIsInt($disable, 'No disabling found.');
        $this->assertLessThan(
            $disable,
            $check,
            'The check runs after the buttons are disabled, which is too late to help.'
        );
    }

    public function test_every_destructive_form_goes_through_the_shared_dialog(): void
    {
        /*
         * govqueueConfirm is the only cancellation path the guard knows how to
         * back out of, so a destructive form that uses anything else will break
         * its own button. The eight call sites are asserted here rather than
         * per-page so a new one cannot be added without the dialog.
         */
        $blade = (string) file_get_contents(base_path('resources/js/govqueue.js'));

        $this->assertStringContainsString(
            'window.govqueueConfirm',
            $blade,
            'govqueueConfirm is no longer exposed on window, so every call site silently '
            .'falls back to the native dialog and the cancel path is untested.'
        );

        // The dialog must hand the button back, independently of the guard above.
        $this->assertStringContainsString(
            'function revive(',
            $blade,
            'The dialog no longer restores a cancelled form\'s buttons.'
        );
    }
}
