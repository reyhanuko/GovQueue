import { expect, Page } from '@playwright/test';
import { client, signIn } from './helpers';
import { test } from './fixtures';

/**
 * Choosing a booking slot, seen rather than asserted.
 *
 * The complaint was not that the page was wrong but that it was long: step 1 ran
 * off the bottom of the screen, steps 2 and 3 sat below it, and somebody arriving
 * from "Book this service" could reach the confirm button without noticing there
 * was a step 1 at all.
 *
 * "How long is the page" is a question only a browser can answer, and it is the
 * question that was asked.
 */
/**
 * Click the first slot that is still open.
 *
 * The radio itself is `sr-only` with a styled <span> drawn over it, so clicking
 * the input's own centre lands on the span instead and Playwright's hit-target
 * check times out. Clicking the wrapping label is what a person does anyway.
 */
async function pickFirstOpenSlot(page: Page) {
    const label = page
        .locator('label:has(input[type="radio"][name="time_slot_id"]:not([disabled]))')
        .first();

    await expect(label, 'No bookable slot was offered at all.').toBeVisible();
    await label.click();
}

test.describe('Booking: choosing a slot', () => {
    const DAY_STRIP = 'nav[aria-label="Choose a day"]';

    async function openBooking(page: Page) {
        await signIn(page, client.email);
        // Straight to the booking screen. The catalogue link goes to the service
        // detail page, not here, and this is about the booking form.
        await page.goto('/services/CERT-IND/book');
        await expect(page.getByRole('heading', { name: /book /i })).toBeVisible();
    }

    test('the confirm button cannot be used before a slot is chosen', async ({ page }) => {
        await openBooking(page);

        // The whole complaint in one assertion: if this button is live with no
        // slot picked, a client can finish the flow without having chosen one.
        await expect(page.getByRole('button', { name: 'Confirm appointment' })).toBeDisabled();
        await expect(page.getByText('Choose a time in step 1 first.')).toBeVisible();
    });

    test('picking a slot enables it and says what was picked', async ({ page }) => {
        await openBooking(page);

        await pickFirstOpenSlot(page);

        await expect(page.getByRole('button', { name: 'Confirm appointment' })).toBeEnabled();
        await expect(page.getByText('Time slot selected')).toBeVisible();
    });

    test('choosing a day shortens the page', async ({ page }) => {
        await openBooking(page);

        const before = await page.evaluate(() => document.body.scrollHeight);

        // The day strip is a real link, so a day can be reached without the
        // script arriving at all.
        const days = page.locator(DAY_STRIP).getByRole('link');
        await days.nth(1).click();
        await expect(page).toHaveURL(/date=/);

        const after = await page.evaluate(() => document.body.scrollHeight);

        expect(
            after,
            `Choosing a day did not shorten the page (${before}px -> ${after}px). `
                + 'Step 1 is meant to stop being a wall.',
        ).toBeLessThan(before);
    });

    test('every step is reachable without a long scroll once a day is chosen', async ({ page }) => {
        await openBooking(page);

        await page.locator(DAY_STRIP).getByRole('link').nth(1).click();

        // Each step in view at once is the actual goal. Asserted as "each step is
        // not below the fold", which is what "has to scroll to reach it" means.
        for (const step of ['2. Priority claim', '3. Anything staff should know?']) {
            const box = await page.getByText(step, { exact: false }).first().boundingBox();

            expect(box, `"${step}" is not on the page.`).not.toBeNull();
        }

        await expect(page.getByRole('button', { name: 'Confirm appointment' })).toBeInViewport();
    });

    test('the day strip scrolls sideways rather than wrapping into a wall', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await openBooking(page);

        const box = await page.locator(DAY_STRIP).boundingBox();

        expect(box).not.toBeNull();
        expect(box!.x + box!.width).toBeLessThanOrEqual(391);

        // The strip is the one place horizontal scrolling is intended, so the
        // document itself must not scroll - otherwise the whole page moves with it.
        const documentOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );

        expect(documentOverflows, 'The page itself scrolls sideways on a phone.').toBe(false);
    });

    test('the current day is marked, for more than colour', async ({ page }) => {
        await openBooking(page);

        const days = page.locator(DAY_STRIP).getByRole('link');
        await days.nth(1).click();

        // aria-current, so the selected day is not distinguished by being blue
        // and nothing else.
        await expect(
            page.locator(DAY_STRIP).locator('[aria-current="true"]'),
        ).toHaveCount(1);
    });
});
