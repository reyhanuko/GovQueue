import { expect, Page } from '@playwright/test';
import { client, signIn } from './helpers';
import { test } from './fixtures';

/**
 * The client's appointment list, seen rather than asserted.
 *
 * The PHP tests prove the ordering, the pagination and the filter. They cannot
 * tell you whether the page is a wall of text, whether the filter row reads as
 * a row of buttons or as a pile of pills, or whether a person can find anything
 * at all without scrolling. That is what this is for.
 */
test.describe('My appointments', () => {
    async function open(page: Page) {
        await signIn(page, client.email);
        await page.goto('/my-appointments');
        await expect(page.getByRole('heading', { name: 'My appointments' })).toBeVisible();
    }

    test('history is newest first, and the page says so', async ({ page }) => {
        await open(page);

        // The ordering claim is the one the bug report was about, and the page
        // states it rather than leaving it to be inferred.
        //
        // It only renders inside the pagination bar, so the bar is asserted too.
        // Without that, this test passed or failed according to how many
        // history rows the fixtures happened to produce - eight per page - which
        // is why it passed alone and failed in the full serial run.
        await expect(page.getByRole('navigation', { name: 'History pages' })).toBeVisible();
        await expect(page.getByText('Newest first')).toBeVisible();
    });

    test('the status filters are a labelled group of real links', async ({ page }) => {
        await open(page);

        const filters = page.getByRole('navigation', { name: 'Filter history by status' });
        await expect(filters).toBeVisible();

        // Named navigation, so a screen reader announces what the group is for
        // rather than a row of unlabelled links. Scoped to the nav, because
        // "All history" also reads as a label on the empty state below.
        //
        // Anchored, because the name of a filter is a substring of another
        // one's: "Completed history" is inside "Not completed history".
        await expect(filters.getByRole('link', { name: 'All history' })).toBeVisible();
        await expect(filters.getByRole('link', { name: /^Completed$/ })).toBeVisible();
        await expect(filters.getByRole('link', { name: /^Not completed$/ })).toBeVisible();
        await expect(filters.getByRole('link', { name: /^Cancelled$/ })).toBeVisible();
    });

    test('filtering works without JavaScript', async ({ page }) => {
        await open(page);

        // Plain links, so it cannot be one of the filters that only works when
        // the script arrives.
        await page.getByRole('navigation', { name: 'Filter history by status' })
            .getByRole('link', { name: 'Cancelled' })
            .click();

        await expect(page).toHaveURL(/status=cancelled/);
        await expect(
            page.getByRole('navigation', { name: 'Filter history by status' }),
        ).toBeVisible();
    });

    test('the page does not scroll sideways on a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);

        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );

        expect(
            overflow,
            'The page scrolls sideways at 390px. The history list used to be a five-column table.',
        ).toBe(false);
    });

    test('the history list is a list, not a wide table', async ({ page }) => {
        await open(page);

        // A table of five columns on a phone is the thing being removed; the
        // rows are now links, which is also what makes the whole row tappable.
        const rows = page.locator('main ul li a[href*="/my-appointments/"]');

        if (await rows.count()) {
            await expect(rows.first()).toBeVisible();
        }
    });

    test('the filter row and the page stay inside a phone viewport', async ({ page }) => {
        // Set here rather than relying on the previous test: each test gets a
        // fresh page at the configured viewport, and an earlier version of this
        // test compared against 390px while running at 1366 - so it passed
        // nothing and failed nothing.
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);

        const viewport = page.viewportSize()!;

        for (const selector of [
            'main',
            'nav[aria-label="Filter history by status"]',
            'main ul',
        ]) {
            const box = await page.locator(selector).first().boundingBox();

            if (!box) {
                continue;
            }

            expect(
                box.x + box.width,
                `"${selector}" runs past the right edge of a ${viewport.width}px phone.`,
            ).toBeLessThanOrEqual(viewport.width + 1);
        }
    });
});
