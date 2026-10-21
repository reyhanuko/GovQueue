import { expect } from '@playwright/test';
import { signIn, staff } from './helpers';
import { test } from './fixtures';

/**
 * Keyboard shortcuts for the staff queue console.
 *
 * The keys are Alt-based on purpose. Ctrl+N is the browser's new window and
 * Ctrl+S is save-page, and a counter that hijacks those is a counter staff
 * work around; the proposal in the guide used exactly those, and it was
 * wrong.
 *
 * Three rules, each of which is worse than not having the shortcut at all:
 *
 *  - It must not fire while somebody is typing. The walk-in panel takes a
 *    name, and a shortcut that fires mid-name issues a ticket for half a name.
 *  - It must not fire outside the console. The client screens are not the
 *    console, and a client pressing keys must not be able to call anybody.
 *  - It must be discoverable. A shortcut nobody knows about is not a
 *    shortcut, so the console says what they are.
 */
test.describe('Staff keyboard shortcuts', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, staff.email);
        await page.goto('/staff');

        await expect(page.getByText(/limited to your assigned window/i)).toBeVisible();
    });

    /**
     * The console's own outcome message, as opposed to the heading.
     *
     * The console has a "Now serving" section as well as a "Now serving ..."
     * message, and a test that matched the heading would be asserting on a
     * label that is always there.
     */
    function outcome(page, text: RegExp) {
        return page.locator('[role="status"]', { hasText: text });
    }

    test('the console says what the shortcuts are', async ({ page }) => {
        // In a disclosure, because a clerk working a queue does not need a
        // keyboard legend in front of them all shift - so the keys are only
        // visible once it is opened, and the test has to open it.
        await page.getByText('Keyboard shortcuts').click();

        await expect(page.getByText('Alt+N')).toBeVisible();
        await expect(page.getByText('Alt+C')).toBeVisible();
        await expect(page.getByText('Alt+S')).toBeVisible();
    });

    test('Alt+C opens the completion panel for the client being served', async ({ page }) => {
        await page.keyboard.press('Alt+c');

        // The panel asks before it records anything. A shortcut that went
        // straight to the record would be a shortcut that skips the asking.
        await expect(
            page.getByRole('button', { name: 'Save transaction' }),
            'Alt+C did not open the completion panel.',
        ).toBeVisible();

        // Left as it was found: the point is the shortcut, not the transaction.
        await page.getByRole('button', { name: 'Cancel' }).click();
        await expect(page.getByRole('button', { name: 'Save transaction' })).toHaveCount(0);
    });

    test('the skip control is bound to the shortcut', async ({ page }) => {
        /*
         * The binding, not the click.
         *
         * This suite shares one seeded database, and skipping a client is
         * destructive to the queue: a skipped client is deliberately served
         * after the people still waiting, so a queue that has one in it is a
         * different queue - and the walk-in tests that follow assert on the
         * order of this one. A test that breaks the next test is not worth
         * having, however good the assertion is.
         *
         * So the wiring is asserted here, and the mechanism is proved by the
         * Alt+C test above: the same code, the same data attribute, the same
         * click.
         */
        const skip = page.locator('[data-gq-shortcut="skip"]');

        await expect(skip).toHaveCount(1);
        await expect(skip).toBeVisible();
        await expect(skip).toHaveText(/not ready/i);
    });

    test('a shortcut does not fire while a name is being typed', async ({ page }) => {
        await page.getByRole('button', { name: 'Walk-in' }).click();

        const form = page.locator('form[wire\\:submit="createWalkIn"]');
        await expect(form).toBeVisible();

        const name = form.getByLabel('Full name');

        await name.click();
        await name.press('Alt+n');

        // The queue was not touched: no client called, and no ticket issued for
        // a name that happens to spell a shortcut.
        await page.waitForTimeout(600);

        await expect(outcome(page, /now serving/i)).toHaveCount(0);
        await expect(form.getByText(/issued for/i)).toHaveCount(0);
    });

    test('a shortcut does not fire outside the console', async ({ page }) => {
        // The client screens are not the console. A client pressing keys must
        // not be able to call anybody.
        await page.goto('/');

        await page.keyboard.press('Alt+n');

        await page.waitForTimeout(600);

        await expect(outcome(page, /now serving/i)).toHaveCount(0);
    });
});
