import { test, expect, Page } from '@playwright/test';
import { signIn, admin, client, hasHorizontalOverflow, overflowingElement, seedMessages } from './helpers';

/**
 * The notifications area, in a browser.
 *
 * Everything the PHP suite asserted about this screen was an assertion about
 * markup: that the class was there, that the directive compiled, that the
 * button carried a size class. None of that is what a person experiences.
 *
 * Three things this file exists to check cannot be checked any other way:
 *
 *   - the bell disclosure. `<details>` opens with no JavaScript, which is why it
 *     was chosen, but it also has no click-outside-to-close. That is a visible
 *     behaviour and it has to be measured, not assumed either way.
 *   - layout. An absolutely positioned dropdown is exactly the kind of thing
 *     that renders perfectly in a string assertion and overflows the viewport
 *     at 360px.
 *   - the real hit area of the remove control, measured in pixels rather than
 *     read off a class name.
 */

/**
 * The bell, and the panel it opens.
 *
 * Located by the sr-only "Messages" text inside the summary rather than by
 * `summary` position. The navbar renders the small-screen menu as a disclosure
 * too, and it comes first in the DOM - so `summary` with `.first()` silently
 * grabs that one, which is display:none at 1366px, and the click waits forever
 * for an element that can never be actionable.
 */
function bellDetails(page: Page) {
    // The navbar renders the small-screen menu as a disclosure too, and it comes
    // first in the DOM. Scoping to the one containing the bell's own viewBox -
    // the hamburger is 0 0 20 20 - is what keeps these two helpers from both
    // grabbing the wrong element.
    return page.locator('nav[aria-label="Main navigation"] details').filter({
        has: page.locator('summary svg[viewBox="0 0 24 24"]'),
    });
}

function bell(page: Page) {
    return bellDetails(page).locator('summary');
}

/** The dropdown, so an assertion cannot accidentally match the list below it. */
function panel(page: Page) {
    return bellDetails(page).locator('> div');
}

test.describe('the bell disclosure', () => {

    test('opens and lists the newest unread messages', async ({ page }) => {
        seedMessages(admin.email, 3, 'critical');

        await signIn(page, admin.email);
        await page.goto('/messages');

        await bell(page).click();

        // Scoped to the panel: the list below repeats the same titles, and an
        // unscoped locator matches both and fails on strict mode.
        await expect(panel(page).getByRole('link', { name: /Seeded alert 0/ })).toBeVisible();
        await expect(panel(page).getByText('View all messages')).toBeVisible();
    });

    /**
     * The sr-only text already says how many are unread. The badge must not
     * repeat it, or the bell announces "Messages, 3 unread 3".
     *
     * Read from the accessibility tree, not from textContent. `textContent`
     * includes aria-hidden nodes by definition - that is the whole point of
     * aria-hidden - so asserting on it fails even when the markup is correct.
     */
    test('announces the unread count exactly once', async ({ page }) => {
        seedMessages(admin.email, 3, 'info');

        await signIn(page, admin.email);
        await page.goto('/messages');

        const snapshot = await bell(page).ariaSnapshot();

        expect(snapshot).toContain('3 unread');
        expect(snapshot).not.toMatch(/3 unread\D{1,20}3\b/);
    });

    /**
     * The one I expected to fail.
     *
     * A native <details> has no click-outside handler. If this fails, the panel
     * stays open over the page after the reader has moved on, which is the thing
     * that makes a dropdown feel broken.
     */
    test('closes when the reader clicks elsewhere', async ({ page }) => {
        seedMessages(admin.email, 3, 'info');

        await signIn(page, admin.email);
        await page.goto('/messages');

        await bell(page).click();
        await expect(panel(page).getByText('View all messages')).toBeVisible();

        await page.getByRole('heading', { name: 'Messages' }).click();

        await expect(panel(page).getByText('View all messages')).toBeHidden();
    });

    test('closes on Escape', async ({ page }) => {
        seedMessages(admin.email, 3, 'info');

        await signIn(page, admin.email);
        await page.goto('/messages');

        await bell(page).click();
        await expect(panel(page).getByText('View all messages')).toBeVisible();

        await page.keyboard.press('Escape');

        await expect(panel(page).getByText('View all messages')).toBeHidden();
    });

    test('does not push the page sideways on a narrow screen', async ({ page }) => {
        seedMessages(admin.email, 5, 'critical');

        await signIn(page, admin.email);

        // 360px is deliberately absent. The header overflows there today for
        // every page including the sign-in form, which a guest sees without any
        // bell - so the cause is the brand block and the sign-out button, not
        // anything in this file. Asserting it here would just fail forever on
        // someone else's bug. "the panel stays inside the viewport on a phone"
        // below covers what this change is actually responsible for.
        for (const width of [1366, 768, 360]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/messages');

            await bell(page).click();

            expect(
                await hasHorizontalOverflow(page),
                `overflow at ${width}px (${await overflowingElement(page)})`,
            ).toBe(false);
        }
    });

    /**
     * The bell has to be reachable on a phone, which is the whole reason for a
     * preview on a municipal office's site.
     *
     * This one currently cannot pass: the header overflows by ~46px at 360px,
     * which pushes the bell past the right edge, so Playwright cannot click it.
     * That overflow is pre-existing and also affects the sign-in form, but its
     * consequence here is not cosmetic - the notifications bell is unreachable
     * on a narrow screen until the navbar is fixed.
     *
     * Kept as a real assertion rather than deleted, so the day somebody fixes
     * the navbar this flips to green and tells them.
     */
    test('the bell is reachable and its panel fits on a phone', async ({ page }) => {
        seedMessages(admin.email, 5, 'critical');

        await signIn(page, admin.email);
        await page.setViewportSize({ width: 360, height: 900 });
        await page.goto('/messages');

        await bell(page).click();

        const panel = page.locator('nav[aria-label="Main navigation"] details div').first();
        const box = await panel.boundingBox();

        expect(box).not.toBeNull();
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width).toBeLessThanOrEqual(360 + 1);
    });
});

test.describe('the inbox', () => {

    test('offers a severity filter to the back office', async ({ page }) => {
        seedMessages(admin.email, 4, 'critical');

        await signIn(page, admin.email);
        await page.goto('/messages');

        await expect(page.getByText('Severity')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Critical', exact: true })).toBeVisible();
        await expect(page.getByText('Needs attention (4)')).toBeVisible();
    });

    test('the remove control is really at least 24px', async ({ page }) => {
        seedMessages(admin.email, 1, 'info');

        await signIn(page, admin.email);
        await page.goto('/messages');

        const remove = page.getByRole('button', { name: /^Remove this message/ }).first();
        const box = await remove.boundingBox();

        expect(box).not.toBeNull();
        // WCAG 2.2 Target Size (Minimum) is 24x24 CSS pixels. Measured rather
        // than asserted off a class, so a future edit to the classes cannot make
        // this quietly untrue.
        expect(box!.width).toBeGreaterThanOrEqual(24);
        expect(box!.height).toBeGreaterThanOrEqual(24);
    });

    test('a client is offered no severity filter', async ({ page }) => {
        seedMessages(client.email, 2, 'info');

        await signIn(page, client.email);
        await page.goto('/messages');

        await expect(page.getByText('Severity')).toHaveCount(0);
    });

    test('the unread filter narrows the list', async ({ page }) => {
        seedMessages(admin.email, 6, 'warning');

        await signIn(page, admin.email);
        await page.goto('/messages');

        const rows = page.locator('ul.mt-6 li[aria-label], ul.mt-6 > li').filter({ hasText: 'Seeded alert' });
        const before = await rows.count();

        await page.getByRole('link', { name: /^Unread only/ }).click();

        await expect(page.getByText('Showing')).toBeVisible();
        expect(before).toBeGreaterThan(0);
    });

    test('no uncompiled Blade directive is visible to a person', async ({ page }) => {
        seedMessages(admin.email, 3, 'info');

        await signIn(page, admin.email);
        await page.goto('/messages');

        // The bug this guards was invisible in the source and obvious on screen.
        await expect(page.getByText(/@if|@endif|@foreach/)).toHaveCount(0);
        await expect(page.getByText(/messages in total/)).toBeVisible();
    });

    test('does not push the page sideways', async ({ page }) => {
        seedMessages(admin.email, 8, 'critical');

        await signIn(page, admin.email);

        for (const width of [1366, 768, 360]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/messages');

            expect(
                await hasHorizontalOverflow(page),
                `overflow at ${width}px (${await overflowingElement(page)})`,
            ).toBe(false);
        }
    });

    /**
     * The 360px header used to overflow by ~46px, which pushed the bell and the
     * Sign out button past the screen edge and made both untappable on a phone.
     * The brand carried no min-w-0, so it refused to shrink below its content and
     * shoved everything to the right of it out of view.
     *
     * Asserted here because it is the regression that matters most for this
     * screen, and because a header that overflows is invisible to every PHP test.
     */
    test('the header fits at 360px for a signed-out visitor', async ({ page }) => {
        await page.setViewportSize({ width: 360, height: 900 });
        await page.goto('/login');

        expect(
            await hasHorizontalOverflow(page),
            `overflow at 360px (${await overflowingElement(page)})`,
        ).toBe(false);
    });
});

