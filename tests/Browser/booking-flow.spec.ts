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

const DAY_STRIP = 'nav[aria-label="Choose a day"]';

/**
 * How long to wait for this page.
 *
 * Deliberately longer than the 7s default. These three tests are the slowest in
 * the suite: each signs in, then loads a booking page that renders a day strip,
 * every time in that day and the whole priority form behind it. Observed at 2.8s
 * on an idle machine and 9.7s when the machine was busy, which is a timing
 * artefact and not a defect - but with the default timeout it failed anyway, and
 * a flaky failure costs more trust than a slow one.
 */
const BOOKING_TIMEOUT = 25_000;

test.describe('Booking: choosing a slot', () => {

    async function openBooking(page: Page) {
        await signIn(page, client.email);
        // Straight to the booking screen. The catalogue link goes to the service
        // detail page, not here, and this is about the booking form.
        await page.goto('/services/CERT-IND/book');
        await expect(page.getByRole('heading', { name: /book /i })).toBeVisible();

        // Wait for the picker itself, not just the heading. The heading renders
        // before the day strip does, so returning on the heading alone let the
        // assertions below race the rest of the page.
        await expect(page.locator(DAY_STRIP)).toBeVisible({ timeout: BOOKING_TIMEOUT });
    }

    test('the confirm button cannot be used before a slot is chosen', async ({ page }) => {
        await openBooking(page);

        // The whole complaint in one assertion: if this button is live with no
        // slot picked, a client can finish the flow without having chosen one.
        await expect(page.getByRole('button', { name: 'Confirm appointment' })).toBeDisabled({ timeout: BOOKING_TIMEOUT });
        await expect(page.getByText('Choose a time in step 1 first.')).toBeVisible({ timeout: BOOKING_TIMEOUT });
    });

    test('picking a slot enables it and says what was picked', async ({ page }) => {
        await openBooking(page);

        await pickFirstOpenSlot(page);

        await expect(page.getByRole('button', { name: 'Confirm appointment' })).toBeEnabled({ timeout: BOOKING_TIMEOUT });
        await expect(page.getByText('Time slot selected')).toBeVisible({ timeout: BOOKING_TIMEOUT });
    });

    test('the page opens on one day, not on every day', async ({ page }) => {
        await openBooking(page);

        // The reported wall. There is no longer a state in which every day is
        // rendered, so this is bounded by a day's worth of times rather than by
        // the length of the booking horizon.
        const radios = await page.getByRole('radio').count();

        expect(
            radios,
            `Step 1 offered ${radios} slots, which looks like the whole horizon again.`,
        ).toBeLessThanOrEqual(20);

        // And exactly one day's times are on the page, under a heading that
        // spells the day out rather than leaving it to be decoded from a card.
        const dayList = page.locator('ul[id^="day-"]');

        await expect(dayList).toHaveCount(1);
        await expect(dayList).toBeVisible();

        // Steps 2 and 3 sit below step 1. They used to sit below a wall; now
        // they sit below one day's times.
        await expect(page.getByText('2. Priority claim')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Confirm appointment' })).toBeInViewport({ timeout: BOOKING_TIMEOUT });
    });

    test('the day strip scrolls sideways rather than widening the page', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await openBooking(page);

        // Regression guard. <fieldset> carries a user-agent default of
        // min-inline-size:min-content, so the strip used to stretch the whole
        // document to fit every day instead of scrolling inside itself:
        // measured at 1706px of fieldset and a 1722px document at this
        // viewport.
        const documentOverflows = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );

        expect(
            documentOverflows,
            'The page itself scrolls sideways on a phone. The strip is the one place '
                + 'horizontal scrolling is meant to happen.',
        ).toBe(false);
    });

    test('changing day swaps the times and keeps the page short', async ({ page }) => {
        await openBooking(page);

        const before = await page.evaluate(() => document.body.scrollHeight);
        const beforeTimes = await page.getByRole('radio').allInnerTexts();

        // The day strip is a real link, so a day can be reached without the
        // script arriving at all.
        await page.locator(DAY_STRIP).getByRole('link').nth(1).click();
        await expect(page).toHaveURL(/date=/);

        const after = await page.evaluate(() => document.body.scrollHeight);
        const afterTimes = await page.getByRole('radio').allInnerTexts();

        expect(afterTimes, 'Changing day did not change the times offered.').not.toEqual(beforeTimes);
        expect(
            after,
            `Picking a day grew the page (${before}px -> ${after}px).`,
        ).toBeLessThan(before + 200);
    });

    test('the current day is marked, for more than colour', async ({ page }) => {
        await openBooking(page);

        const days = page.locator(DAY_STRIP).getByRole('link');
        await days.nth(1).click();

        // aria-current, so the selected day is not distinguished by being blue
        // and nothing else.
        await expect(page.locator(DAY_STRIP).locator('[aria-current="true"]')).toHaveCount(1);
    });

    test('availability is a badge, not more text to decode as a date', async ({ page }) => {
        await openBooking(page);

        const card = page.locator(DAY_STRIP).getByRole('link').first();
        const text = (await card.innerText()).replace(/\s+/g, ' ').trim();

        // "Oct - 12 free" ran the month and the free count together on one line,
        // with the count's 12 sitting next to the date's own 12. Each fact is
        // now on its own line, and the count is styled as a badge.
        expect(text, `The day card still reads like "${text}".`).not.toMatch(/[A-Z][a-z]{2}\s*·\s*\d+/);
        await expect(card.locator('span.rounded-full')).toHaveCount(1);
    });
});

/**
 * Moving an appointment is the same decision as booking one: which day, which
 * time.
 *
 * It used to be a separate copy of that decision which had drifted - it rendered
 * every day at once and its button was never gated on a slot being chosen. The
 * same failures, on the same question, one screen over.
 */
test.describe('Booking: moving an appointment', () => {
    const mover = { email: 'mover@browser.test' };

    async function openMoveScreen(page: Page) {
        await signIn(page, mover.email);
        await page.goto('/my-appointments');
        await page.getByText('GQ-BROWSER-MOVE').first().click();
        await page.getByRole('link', { name: 'Move to another slot' }).click();
        await expect(page.getByRole('heading', { name: /move your /i })).toBeVisible();
    }

    test('the move screen uses the same day strip', async ({ page }) => {
        await openMoveScreen(page);

        await expect(page.locator(DAY_STRIP)).toBeVisible();
        expect(
            await page.getByRole('radio').count(),
            'The move screen still renders every day at once.',
        ).toBeLessThanOrEqual(20);
    });

    test('moving is refused until a new slot is chosen', async ({ page }) => {
        await openMoveScreen(page);

        await expect(page.getByRole('button', { name: 'Move my appointment' })).toBeDisabled();
        await expect(page.getByText('Not chosen yet')).toBeVisible();
    });

    test('the current booking is marked as such, and stays selectable', async ({ page }) => {
        await openMoveScreen(page);

        // The client has to be able to keep the slot they have, and the screen
        // has to say which one that is rather than presenting it as a choice
        // between six identical-looking times.
        await expect(page.getByText('Your current slot')).toBeVisible();
        await expect(page.getByText('Your booking')).toBeVisible();
    });

    test('picking a new time states it beside the button', async ({ page }) => {
        await openMoveScreen(page);

        await page
            .locator('label:has(input[type="radio"][name="time_slot_id"]:not([disabled]))')
            .first()
            .click();

        await expect(page.getByRole('button', { name: 'Move my appointment' })).toBeEnabled();
        // "New slot" matches both the label and its wrapping element.
        await expect(page.getByText('New slot').first()).toBeVisible();
    });
});
