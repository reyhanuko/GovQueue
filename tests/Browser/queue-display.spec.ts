import { expect } from '@playwright/test';
import { confirmDialog } from './helpers';
import { test } from './fixtures';

/**
 * The lobby display, which nobody had ever run in a browser.
 *
 * Two hypotheses are covered here, both of which only exist at runtime.
 *
 * 1. The board re-renders every 10 seconds. It has to: Livewire skips a
 *    response whose payload is unchanged, and the queue query returns identical
 *    results on most polls, so PublicQueueDisplay::render() bumps a counter
 *    purely to force a redraw. That redraw is Livewire DOM-morphing a page
 *    full of Alpine directives - including `x-show` and `x-transition` on the
 *    offline banner. If morphing re-evaluates those, the banner flashes every
 *    ten seconds on a television bolted to the wall of a municipal office.
 *
 * 2. A malformed `?date=` reaches Carbon::parse() through an unauthenticated
 *    route with no throttle, which throws - and re-throws on every poll. That is
 *    an anonymous, repeatable 500 on the screen the whole lobby looks at.
 *
 * The display is public by design: queue numbers and order only, never names,
 * services or priority claims.
 */
test.describe('Public queue display', () => {
    test('the board renders for an anonymous visitor', async ({ page }) => {
        const response = await page.goto('/queue-display');

        expect(response?.status()).toBe(200);

        // The board's job is to show the window and the queue. Asserted on those
        // rather than on the connection wording, which is not what this screen is
        // for and which the flash test below already covers indirectly.
        await expect(page.locator('header h1')).toBeVisible();
        await expect(page.getByText(/queue is empty|nobody waiting|now serving/i).first()).toBeVisible();
    });

    test('it never shows a client name, service or priority claim', async ({ page }) => {
        const body = (await page.locator('body').innerText()).toUpperCase();

        // The privacy property of this screen, asserted on the rendered text
        // rather than on the absence of a class name.
        expect(body).not.toContain('SENIOR CITIZEN');
        expect(body).not.toContain('PERSON WITH DISABILITY');
        expect(body).not.toContain('PREGNANT');
    });

    test('a malformed date does not break it', async ({ page }) => {
        /*
         * Waiting only for the response to commit.
         *
         * This test asserts a status code and nothing else, so loading five full
         * Livewire pages - each booting the framework, rendering the board, and
         * starting a 10-second poll - to read a number off the response is
         * wasteful and, under load, flaky. Five rapid full loads had failed
         * twice with net::ERR_NO_BUFFER_SPACE and net::ERR_ABORTED, which read
         * exactly like an application fault and were neither.
         *
         * 'commit' returns as soon as the response headers arrive, which is the
         * first moment the status code exists. The other tests in this file are
         * the ones that need the page to actually render.
         */
        const bad = ['not-a-date', 'x', '../../etc/passwd', '0000-00-00', 'tomorrow-ish'];

        for (const value of bad) {
            const response = await page.goto('/queue-display?date=' + encodeURIComponent(value), {
                waitUntil: 'commit',
            });

            expect(
                response?.status(),
                `?date=${value} returned ${response?.status()} instead of a board.`,
            ).toBe(200);
        }
    });

    test('a malformed date falls back to today rather than an empty board', async ({ page }) => {
        // An empty board reads to staff as "nobody is waiting", which is the one
        // thing on this screen that must never be a lie.
        await page.goto('/queue-display?date=' + encodeURIComponent('not-a-date'));

        const heading = await page.locator('header').innerText();

        // The display formats the date itself as "l, M d Y" - e.g.
        // "Saturday, Oct 11 2026". Compared against the same format rather than
        // a locale string, so the assertion does not depend on the machine's
        // regional settings.
        const expected = new Date()
            .toLocaleDateString('en-US', { weekday: 'long' })
            .concat(', ')
            .concat(
                new Date().toLocaleDateString('en-US', { month: 'short' })
                    .concat(' ')
                    .concat(String(new Date().getDate()))
                    .concat(' ')
                    .concat(String(new Date().getFullYear())),
            );

        expect(heading).toContain(expected);
    });

    /**
     * The flash hypothesis, and the reason this file exists.
     *
     * Sampled across several poll cycles rather than asserted once: a
     * transition that re-triggers looks exactly like a stable one at any single
     * observation, which is why "it looked fine when I looked at it" and "it
     * flashes every ten seconds" can both be true.
     */
    test('the board does not flash between polls', async ({ page }) => {
        await page.goto('/queue-display');

        const banner = page.locator('[role="alert"]').first();

        // Wait for the board to have rendered and Alpine to have settled before
        // sampling, or the first observation measures the initial paint.
        await page.waitForTimeout(1500);

        const samples: Array<{ opacity: string; visible: boolean }> = [];

        for (let i = 0; i < 14; i++) {
            samples.push(
                await banner.evaluate((el) => {
                    const style = window.getComputedStyle(el);

                    return {
                        opacity: style.opacity,
                        visible: el.getBoundingClientRect().height > 0,
                    };
                }),
            );

            // Comfortably inside a 10s poll cycle, and deliberately not
            // aligned to it - a fixed sleep would sample the same point in the
            // cycle every time and could miss the transition entirely.
            await page.waitForTimeout(750);
        }

        const flickers = samples.filter(
            (s) => s.visible && Number(s.opacity) < 0.9,
        );

        expect(
            flickers,
            `The offline banner faded to ${flickers.map((f) => f.opacity).join(', ')} across `
                + `${samples.length} samples. A re-triggered x-transition on every poll is a banner `
                + 'that flashes ten times a minute on a wall-mounted screen.',
        ).toEqual([]);
    });

    /**
     * The counterpart to the flash check, and the reason it matters.
     *
     * A board that never changes is just as broken as one that flickers, so the
     * fix for "it flickers" must not be "stop updating". Asserted by counting the
     * Livewire requests rather than by reading the clock: the stamp deliberately
     * says "just now" for anything under ten seconds, and the poll runs every
     * ten seconds, so the label is almost always "just now" and comparing it
     * proves nothing either way.
     */
    test('the board actually polls, so it is not frozen', async ({ page }) => {
        let polls = 0;

        page.on('request', (request) => {
            // Livewire's update endpoint carries a hash: /livewire-<id>/update.
            // Matching on '/livewire/update' silently never matches, and the
            // test then reports a frozen board that is in fact polling normally.
            if (/\/livewire-[^/]+\/update$/.test(request.url())) {
                polls++;
            }
        });

        await page.goto('/queue-display');
        await page.waitForTimeout(1000);

        const baseline = polls;

        // Comfortably longer than one 10s cycle, so a working poll cannot be
        // missed by an unlucky boundary.
        await page.waitForTimeout(12_000);

        expect(
            polls - baseline,
            'No poll fired in twelve seconds. The socket is unavailable here, so this '
                + 'interval is the only thing keeping the board current - without it the '
                + 'lobby sees a frozen screen.',
        ).toBeGreaterThanOrEqual(1);
    });

    test('a visitor can get off the display', async ({ page }) => {
        // The display is a page of the site now, so the navigation is the way
        // off it. It used to carry its own "Back to GovQueue" link because the
        // board had no navigation at all - and a resident who reached it by
        // clicking "Queue Display" got a black page with nothing to click.
        await page.goto('/queue-display');

        const home = page
            .locator('nav[aria-label="Main navigation"]')
            .getByRole('link', { name: /govqueue lg/i });

        await expect(home).toBeVisible();
        await home.click();
        await page.waitForURL((url) => !url.pathname.includes('queue-display'));
    });

    test('the display carries the site navigation', async ({ page }) => {
        // It used to carry none: a standalone dark document, on the grounds
        // that it is opened full-screen on a lobby TV. Which it is - and also
        // a page a resident reaches from the navigation, who then had a black
        // screen in the middle of a white site and no way back.
        await page.goto('/queue-display');

        await expect(page.locator('nav[aria-label="Main navigation"]')).toHaveCount(1);
        await expect(confirmDialog.dialog(page)).toHaveCount(0);
    });

    test('the display looks like the rest of the site', async ({ page }) => {
        // The page was a full-bleed dark board. It is a page now, so it is
        // light like every other page - measured rather than asserted off a
        // class name, so a change to the markup cannot make this quietly
        // untrue.
        await page.goto('/queue-display');

        const background = await page.evaluate(
            () => window.getComputedStyle(document.body).backgroundColor,
        );

        const elsewhere = await page.goto('/').then(() =>
            page.evaluate(() => window.getComputedStyle(document.body).backgroundColor),
        );

        expect(
            background,
            'The queue display is a different colour from the rest of the site, so '
                + 'somebody who clicks "View live queue" lands somewhere that does '
                + 'not look like the same website.',
        ).toBe(elsewhere);
    });
});
