import { expect, Locator, Page } from '@playwright/test';
import { signIn, staff } from './helpers';
import { test } from './fixtures';

/**
 * The decisions a clerk makes at the counter.
 *
 * Three of them were made with the browser's own confirm(): an operating
 * system window that says one sentence, offers no room to write down why,
 * and looks like something the page did rather than something the office
 * decided. And the confirmation of a decision was a banner at the top of a
 * long page, which hid itself after eight seconds.
 *
 * These are the things a browser can see and a PHP test cannot: that a
 * dialog opens, that it says what is about to happen, and that the result is
 * shown where the decision was taken.
 */
test.describe('Staff console decisions', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, staff.email);
        await page.goto('/staff');

        await expect(page.getByText(/limited to your assigned window/i)).toBeVisible();
    });

    /**
     * Issue a walk-in that claims a category, so there is a ticket at this
     * window with a claim on it to look at.
     */
    async function issueClaimedWalkIn(page: Page, name: string) {
        await page.getByRole('button', { name: 'Walk-in' }).click();

        const form = page.locator('form[wire\\:submit="createWalkIn"]');
        await expect(form).toBeVisible();

        await page.getByLabel('Full name').fill(name);
        await form.locator('input[type="checkbox"][value="pwd"]').check();
        await form.getByLabel(/pwd reference/i).fill('PWD-12345');

        await form.getByRole('button', { name: /issue/i }).click();

        await expect(form).toBeHidden();
    }

    /**
     * The decision dialog, once it is open.
     */
    function dialog(page: Page) {
        return page.locator('[role="dialog"]');
    }

    test('the claim is named, not called "Regular"', async ({ page }) => {
        await issueClaimedWalkIn(page, 'Nina Dizon');

        await page.getByRole('button', { name: 'Verify claim' }).first().click();

        const panel = dialog(page);
        await expect(panel).toBeVisible();

        // The clerk is being asked to decide about a PWD claim. "Regular" was
        // what the panel used to say, because the label showed the *grant*
        // rather than the *claim* - so the clerk had to open the panel to
        // find out what they were being asked about.
        await expect(panel.getByText('Person with Disability')).toBeVisible();
        await expect(panel).not.toContainText('Regular');
    });

    test('a refusal asks for a reason, and says what the client will be told', async ({ page }) => {
        await issueClaimedWalkIn(page, 'Pedro Lim');

        await page.getByRole('button', { name: 'Verify claim' }).first().click();

        const panel = dialog(page);
        await expect(panel).toBeVisible();

        await panel.getByRole('button', { name: 'Refuse claim' }).click();

        // The reason is required, and the client is told it. A refusal the
        // client cannot read the reason for is a refusal they cannot act on.
        await expect(panel.getByLabel(/why the claim is refused/i)).toBeVisible();
        await expect(panel.getByText(/the client sees this/i)).toBeVisible();

        await panel.getByLabel(/why the claim is refused/i).fill('Bring the PWD ID and come back.');
        await panel.getByRole('button', { name: 'Refuse the claim' }).click();

        // The result is shown where the decision was taken, and it stays there.
        await expect(panel.getByText(/claim refused/i)).toBeVisible();
        await expect(panel.getByText(/bring the pwd id and come back/i)).toBeVisible();
    });

    test('a no-show says what is about to be recorded before it is recorded', async ({ page }) => {
        // Whoever is at the window. The browser suite shares one database, so
        // there may already be somebody there.
        const callNext = page.getByRole('button', { name: 'Call next client' });
        if (await callNext.count()) {
            await callNext.click();
        }

        const noShow = page.getByRole('button', { name: 'Mark no-show' }).first();
        await expect(noShow).toBeVisible();
        await noShow.click();

        const panel = dialog(page);
        await expect(panel).toBeVisible();
        await expect(panel.getByText(/the place is released/i)).toBeVisible();

        await panel.getByRole('button', { name: /yes, mark as a no-show/i }).click();

        await expect(panel.getByText(/marked as a no-show/i)).toBeVisible();

        /*
         * Put somebody back at the window.
         *
         * This suite shares one seeded database, and the window being empty
         * breaks the specs that run after this one - the keyboard shortcuts
         * are bound to the client being served, so with nobody there they
         * have nothing to act on. A test that breaks the next test is not
         * worth having, however good the assertion is.
         */
        await panel.getByRole('button', { name: 'Done' }).click();
        await page.getByRole('button', { name: 'Call next client' }).click();
        await expect(page.getByRole('button', { name: 'Mark no-show' }).first()).toBeVisible();
    });

    test('the console says who has been served today', async ({ page }) => {
        // The heading is on the page whether or not anybody has been served:
        // a clerk working a counter needs to know the day's count, and the
        // names are one click away.
        await expect(page.getByText(/served today/i)).toBeVisible();
    });

    test('the dialog is a dialog, not the browser confirm', async ({ page }) => {
        await issueClaimedWalkIn(page, 'Tina Lim');

        // The browser's confirm() is a dialog the page does not own: it has no
        // role="dialog", it cannot carry a reason, and it cannot be styled.
        let nativeDialog = false;
        page.on('dialog', (dialog) => {
            nativeDialog = true;
            dialog.dismiss();
        });

        await page.getByRole('button', { name: 'Verify claim' }).first().click();

        await expect(dialog(page)).toBeVisible();
        expect(nativeDialog, 'The page opened the browser confirm() instead of its own dialog.').toBe(false);
    });

    test('the dialog can be dismissed with the keyboard', async ({ page }) => {
        await issueClaimedWalkIn(page, 'Rina Lim');

        await page.getByRole('button', { name: 'Verify claim' }).first().click();
        await expect(dialog(page)).toBeVisible();

        // Escape closes it. A clerk who opened the panel by mistake must be
        // able to get out of it without hunting for the button.
        await page.keyboard.press('Escape');

        await expect(dialog(page)).toHaveCount(0);
    });

    test('a transaction that cannot be completed says so, and does not go blank', async ({ page }) => {
        // Whoever is at the window.
        const callNext = page.getByRole('button', { name: 'Call next client' });
        if (await callNext.count()) {
            await callNext.click();
        }

        await page.getByRole('button', { name: 'Not completed' }).first().click();

        const panel = dialog(page);
        await expect(panel).toBeVisible();

        // The reason is required: the client is told this, and it is what
        // they do next.
        await panel.getByRole('button', { name: 'Record as not completed' }).click();
        await expect(panel.getByText(/please say why/i)).toBeVisible();

        await panel.getByLabel(/why it could not be completed/i).fill('Client did not bring the PWD ID.');
        await panel.getByRole('button', { name: 'Record as not completed' }).click();

        /*
         * It used to go blank here.
         *
         * The action cleared the ticket the dialog was about, and the
         * dialog's condition asked for that ticket - so nothing rendered
         * inside it. A clerk was left with an empty box over the console
         * that only a refresh would clear.
         */
        await expect(panel.getByText(/recorded as not completed/i)).toBeVisible();
        await expect(panel.getByText(/the client has been told why/i)).toBeVisible();

        /*
         * Put somebody back at the window.
         *
         * This suite shares one seeded database, and the window being empty
         * breaks the specs that run after this one - the keyboard shortcuts
         * are bound to the client being served, so with nobody there they
         * have nothing to act on.
         */
        await panel.getByRole('button', { name: 'Done' }).click();
        await page.getByRole('button', { name: 'Call next client' }).click();
        await expect(page.getByRole('button', { name: 'Mark no-show' }).first()).toBeVisible();
    });
});
