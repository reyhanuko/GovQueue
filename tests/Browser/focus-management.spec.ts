import { expect } from '@playwright/test';
import { signIn, staff } from './helpers';
import { test } from './fixtures';

/**
 * The keyboard follows what just happened.
 *
 * A Livewire action reports its outcome in a live region, and a screen reader
 * announces that on its own. A sighted keyboard user got nothing: the message
 * appeared on the screen, the focus stayed on the button they had pressed, and
 * pressing Enter again repeated the action instead of reading the result.
 *
 * So the focus moves to the message. Three things it must not do, and each has
 * a test here because each of them is worse than doing nothing:
 *
 *  - It must not move while somebody is typing. The walk-in panel re-renders
 *    as a name is typed, and a caret thrown out of the field loses the rest of
 *    the name.
 *  - It must not move to a message that is about to disappear. The console's
 *    messages hide themselves after a few seconds, and a message removed while
 *    it holds the focus takes the focus with it - which is the problem this
 *    was fixing, arriving by another road.
 *  - It must not move while a dialog is open, because a modal dialog owns the
 *    focus.
 */
test.describe('Focus after a Livewire action', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, staff.email);
        await page.goto('/staff');

        await expect(page.getByText(/limited to your assigned window/i)).toBeVisible();
    });

    /** The walk-in form, opened and ready. */
    async function openWalkInPanel(page) {
        await page.getByRole('button', { name: 'Walk-in' }).click();

        const form = page.locator('form[wire\\:submit="createWalkIn"]');
        await expect(form).toBeVisible();

        return form;
    }

    test('the message that reports the outcome takes the focus', async ({ page }) => {
        const panel = await openWalkInPanel(page);

        await panel.getByLabel('Full name').fill('Focus Test');
        await panel.getByRole('button', { name: /issue ticket/i }).click();

        // Located by what it says, not by its role alone: the console has one
        // status region, but the page has more than one thing that could match
        // a bare role selector, and a test that fails on ambiguity teaches
        // people to ignore it.
        const message = page.locator('[role="status"]', { hasText: /issued for/i });

        await expect(message).toBeVisible();
        await expect(message).toBeFocused();
    });

    test('a message that has the focus is not taken away by its own timer', async ({ page }) => {
        const panel = await openWalkInPanel(page);

        await panel.getByLabel('Full name').fill('Focus Timer');
        await panel.getByRole('button', { name: /issue ticket/i }).click();

        const message = page.locator('[role="status"]', { hasText: /issued for/i });

        await expect(message).toBeFocused();

        // The console hides a message after eight seconds. If it hid one that
        // was holding the focus, the focus would fall to the body and the next
        // Tab would start from the top of the page - which is the exact failure
        // this whole thing exists to prevent, arriving by another road.
        await page.waitForTimeout(9_000);

        await expect(message, 'The message hid itself while it was holding the focus.').toBeVisible();
        await expect(message).toBeFocused();
    });

    test('the caret is not thrown out of a field somebody is typing in', async ({ page }) => {
        const panel = await openWalkInPanel(page);

        const name = panel.getByLabel('Full name');

        await name.click();
        await name.type('Maria Dela Cruz', { delay: 20 });

        // The panel updates as the name is typed - wire:model.live with a
        // debounce - so there is an update while the field has the focus.
        await page.waitForTimeout(700);

        await expect(name, 'The focus left the field while it was being typed into.').toBeFocused();
        await expect(name).toHaveValue('Maria Dela Cruz');
    });
});
