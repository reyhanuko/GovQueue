import { expect, Page } from '@playwright/test';
import { client, signIn } from './helpers';
import { test } from './fixtures';

/**
 * Two defects that only exist once a browser has parsed the page.
 *
 * Neither can be caught by a feature test asserting on strings: a peso sign
 * that has been double-encoded still contains the digits and the comma, and a
 * malformed SVG path is still a well-formed attribute. Both only show up as
 * something a person sees, or as a console error nobody reads.
 */
test.describe('Page integrity', () => {
    /** Console errors and warnings, which is where an SVG parse error lands. */
    async function collectPageProblems(page: Page) {
        const problems: string[] = [];

        /*
         * "Failed to load resource: net::ERR_FAILED" is filtered out on purpose.
         * The suite blocks every external request by design, so a blocked
         * analytics or font request is the fixture working, not a defect. What
         * is kept includes script errors, uncaught exceptions and the browser's
         * own SVG parse messages - which is the whole point, since a malformed
         * path is reported exactly there.
         */
        const isBlockedExternal = (text: string) =>
            text.includes('Failed to load resource') || text.includes('net::ERR_');

        page.on('console', (message) => {
            if (message.type() === 'error' || message.type() === 'warning') {
                if (!isBlockedExternal(message.text())) {
                    problems.push(`${message.type()}: ${message.text()}`);
                }
            }
        });
        page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));

        return problems;
    }

    test('the booking page loads no console errors or warnings', async ({ page }) => {
        const problems = await collectPageProblems(page);

        await signIn(page, client.email);
        await page.goto('/services/CERT-IND/book');
        await expect(page.getByRole('heading', { name: /book /i })).toBeVisible();

        // Give any deferred script a chance to log before the assertion.
        await page.waitForTimeout(500);

        expect(
            problems,
            'The booking page reported console problems:\n  ' + problems.join('\n  '),
        ).toEqual([]);
    });

    /**
     * Catches an icon that renders as nothing at all.
     *
     * Deliberately narrow. getTotalLength() throws only on a path that failed to
     * parse entirely, and the browser's behaviour on a *partially* bad path is
     * to log an error and keep the segments it did understand - so a path with
     * one broken arc still reports a length and this test still passes. It did
     * exactly that when the checkmark's arc was sabotaged.
     *
     * The parse errors themselves are caught by the console test above, which
     * does bite on that bug. Between them the two cover it.
     */
    test('every inline SVG icon on the booking page has drawable geometry', async ({ page }) => {
        await signIn(page, client.email);
        await page.goto('/services/CERT-IND/book');
        await page.getByRole('heading', { name: /book /i }).waitFor();

        const broken = await page.evaluate(() => {
            return Array.from(document.querySelectorAll('svg path'))
                .filter((p) => {
                    try {
                        return p.getTotalLength() === 0;
                    } catch {
                        return true;
                    }
                })
                .map((p) => p.getAttribute('d') ?? '(no d attribute)');
        });

        expect(broken, 'These SVG paths drew nothing:\n  ' + broken.join('\n  ')).toEqual([]);
    });

    /**
     * A peso sign that survived a double-encode is still mostly-correct looking
     * in source: the digits and comma are untouched. Only the rendered glyph
     * gives it away, which is why this reads the text a person would read.
     *
     * BUS-PERMIT specifically, because it carries a fee. CERT-IND was the
     * obvious choice and reads "Free", so a test aimed at it would have passed
     * while proving nothing about the peso sign.
     */
    test('the peso sign renders as a peso sign, not as three characters', async ({ page }) => {
        await signIn(page, client.email);
        await page.goto('/services/BUS-PERMIT/book');

        const fee = page
            .locator('dt', { hasText: /^Fee$/i })
            .locator('xpath=following-sibling::dd[1]');

        await expect(fee).toBeVisible();

        const text = (await fee.innerText()).replace(/\s+/g, ' ').trim();

        expect(text, `The fee reads as "${text}".`).not.toBe('Free');

        // The mojibake was the literal characters U+00E2 U+201A U+00B1, which
        // render as "a-circumflex, single low quote, plus/minus".
        expect(text, 'The fee contains a mis-encoded peso sign.').not.toContain('â');
        expect(text, `The fee reads as "${text}".`).toMatch(/^₱[\d,]+\.\d{2}$/);
    });

    /** The service page renders the same amount by a different route. */
    test('the service page shows a peso amount, not mojibake', async ({ page }) => {
        await signIn(page, client.email);
        await page.goto('/services/BUS-PERMIT');

        const badge = page.locator('h1').locator('xpath=following::span[1]');
        const text = (await badge.innerText()).trim();

        expect(text, `The fee badge reads as "${text}".`).not.toContain('â');
        expect(text, `The fee badge reads as "${text}".`).toMatch(/^₱[\d,]+\.\d{2}$/);
    });

    test('the service page shows one day of times, not the whole horizon', async ({ page }) => {
        await signIn(page, client.email);
        await page.goto('/services/CERT-IND');

        const times = page.locator('h3 + ul li');
        const count = await times.count();

        // The preview used to list every slot across the booking horizon, which
        // pushed "Book this service" far below the fold.
        expect(
            count,
            `The service page listed ${count} times, which looks like the whole horizon again.`,
        ).toBeGreaterThan(0);
        expect(count, 'The service page still lists every slot at once.').toBeLessThanOrEqual(30);
    });

    test('the service page offers a day strip', async ({ page }) => {
        await signIn(page, client.email);
        await page.goto('/services/CERT-IND');

        const strip = page.locator('nav[aria-label="Choose a day"]');
        await expect(strip).toBeVisible();

        // Same partial as the booking screen, so it has to behave the same way.
        await expect(strip.locator('[aria-current="true"]')).toHaveCount(1);
    });

    test('the service page does not scroll sideways on a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await signIn(page, client.email);
        await page.goto('/services/CERT-IND');

        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );

        expect(overflow, 'The service page scrolls sideways at 390px.').toBe(false);
    });
});