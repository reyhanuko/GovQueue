import { expect } from '@playwright/test';
import { admin, newcomer, signIn, staff } from './helpers';
import { test } from './fixtures';

/**
 * "You are here" has to be visible, not merely announced.
 *
 * The navigation marks the current page twice: aria-current="page" for
 * assistive technology, and a tint plus a border for everyone else. Both
 * matter, and they are separate jobs - a marker that is only in the
 * accessibility tree is invisible to the person looking at the screen.
 *
 * The messages page is where that gap showed. The bell already carried
 * aria-current, so a screen reader knew it was the inbox. Nothing told
 * anybody else: the bell looked exactly the same on the inbox as on the
 * dashboard, and on a desktop it is the only way in to the inbox.
 *
 * The other half is that the highlight was being *lost* on the screens
 * underneath a section: the booking flow, and the administrator's forms.
 */
test.describe('The current page is marked visibly', () => {
    /**
     * The background colour of the element marked as the current page.
     *
     * Computed rather than read off a class name, so a change to the markup
     * cannot make this quietly untrue - and "not transparent" rather than a
     * particular colour, so the test says "you can see it" instead of saying
     * how.
     */
    async function currentBackground(page) {
        return page
            .locator('[aria-current="page"]:visible')
            .first()
            .evaluate((el) => window.getComputedStyle(el).backgroundColor);
    }

    test('the booking screen keeps the Services link lit', async ({ page }) => {
        await signIn(page, newcomer.email);
        await page.goto('/services/CERT-IND/book');

        const current = page.locator('[aria-current="page"]:visible');

        await expect(current).toHaveCount(1);
        await expect(current).toHaveText('Services');

        expect(
            await currentBackground(page),
            'The Services link is marked for a screen reader but looks no different '
                + 'from an ordinary link, so nobody can see that the booking screen '
                + 'is still under Services.',
        ).not.toBe('rgba(0, 0, 0, 0)');
    });

    test('the messages page marks the bell', async ({ page }) => {
        await signIn(page, staff.email);

        /*
         * The bell, found by its own icon.
         *
         * The navbar renders the small-screen menu as a disclosure too, and it
         * comes first in the DOM - so a bare "summary" grabs that one, which is
         * display:none at 1366px. The bell's icon is the 24x24 one; the menu
         * button's is 20x20.
         */
        const bell = page
            .locator('nav[aria-label="Main navigation"] details')
            .filter({ has: page.locator('summary svg[viewBox="0 0 24 24"]') })
            .locator('summary');

        await page.goto('/staff');
        const elsewhere = await bell.evaluate((el) => window.getComputedStyle(el).backgroundColor);

        await page.goto('/messages');

        await expect(page.locator('[aria-current="page"]:visible')).toHaveCount(1);

        const onInbox = await bell.evaluate((el) => window.getComputedStyle(el).backgroundColor);

        expect(
            onInbox,
            'The bell is the only way into the inbox on a desktop, and it looks '
                + 'identical to how it looks on every other page.',
        ).not.toBe('rgba(0, 0, 0, 0)');

        expect(
            onInbox,
            'The bell is marked the same way on the inbox as it is on a page it '
                + 'does not lead to, so the mark says nothing.',
        ).not.toBe(elsewhere);
    });

    test('an administrator form keeps its section lit', async ({ page }) => {
        await signIn(page, admin.email);
        await page.goto('/admin/services/create');

        const current = page.locator('[aria-current="page"]:visible');

        // The top bar marks "Admin" and the sidebar marks "Services": both are
        // correct, and the one that matters here is the sidebar.
        await expect(current.filter({ hasText: 'Services' })).toHaveCount(1);

        expect(
            await page
                .locator('[aria-current="page"]:visible')
                .filter({ hasText: 'Services' })
                .first()
                .evaluate((el) => window.getComputedStyle(el).backgroundColor),
            'The sidebar marks Services for a screen reader but does not look '
                + 'different, so the form does not say where you are.',
        ).not.toBe('rgba(0, 0, 0, 0)');
    });
});
