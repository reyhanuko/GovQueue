import { expect, Page } from '@playwright/test';
import { admin, newcomer, seedMessages, signIn, staff } from './helpers';
import { test } from './fixtures';

/**
 * How big the tappable things actually are.
 *
 * WCAG 2.2 Target Size (Minimum) asks for 24x24 CSS pixels at Level AA; iOS
 * asks for 44 and Android for 48. The app has never been measured against any
 * of them, and the guide used to suggest pasting a snippet into the browser
 * console - which measures the screen you happen to have open, measures
 * nothing on a real phone, and produces a list in a console that nobody reads
 * twice.
 *
 * So it is a test. It measures the rendered page on a phone-sized viewport and
 * fails on a target that is too small.
 *
 * Three things make a naive measurement wrong, and each is handled:
 *
 *  - **A stretched link.** The service name in the catalogue is a link whose
 *    ::after covers the whole card, so the real target is the card and not the
 *    21 pixels of text in it. Measuring the text would report a violation that
 *    does not exist.
 *  - **A checkbox with a label.** The input is 13 pixels square; the label
 *    around it is the thing a finger actually lands on, and clicking the text
 *    toggles the box.
 *  - **An inline link.** WCAG 2.2 exempts a target in a sentence, whose size
 *    is constrained by the line-height of the text around it. "Forgot your
 *    password?" is one of those.
 *
 * The skip link is exempt too, and for a different reason: it is 1x1 pixels
 * until it is focused, at which point it is a full-sized target.
 */
const MINIMUM = 24;

type Screen = { as: 'public' | 'client' | 'staff' | 'admin'; url: string };

const SCREENS: Screen[] = [
    { as: 'public', url: '/' },
    { as: 'public', url: '/services' },
    { as: 'public', url: '/services/CERT-IND' },
    { as: 'public', url: '/login' },
    { as: 'public', url: '/register' },
    { as: 'public', url: '/queue-display' },
    { as: 'client', url: '/my-appointments' },
    { as: 'staff', url: '/staff' },
    { as: 'admin', url: '/admin' },
    { as: 'admin', url: '/admin/windows' },
    { as: 'admin', url: '/admin/windows/create' },
    { as: 'admin', url: '/admin/holidays' },
    { as: 'admin', url: '/messages' },
    { as: 'admin', url: '/admin/reports' },
    { as: 'admin', url: '/admin/audit' },
];

/**
 * Every target on the page that is too small, described well enough to find.
 */
async function tooSmall(page: Page): Promise<string[]> {
    return page.evaluate((minimum) => {
        const selector = [
            'a[href]',
            'button',
            'input:not([type="hidden"])',
            'select',
            'textarea',
            'summary',
            '[role="button"]',
            '[role="checkbox"]',
            '[role="radio"]',
            '[role="tab"]',
        ].join(',');

        /**
         * The box a finger would have to land on.
         *
         * Not always the element's own box: a stretched link is as big as the
         * card it covers, and a checkbox is as big as its label.
         */
        const effectiveBox = (el: Element): DOMRect => {
            const own = el.getBoundingClientRect();

            const label = (el as HTMLElement).closest('label');

            if (label && /^(input|select)$/i.test(el.tagName)) {
                const labelBox = label.getBoundingClientRect();

                if (labelBox.width > 0 && labelBox.height > 0) {
                    return labelBox;
                }
            }

            const after = window.getComputedStyle(el, '::after');

            if (after.content !== 'none' && after.position === 'absolute') {
                let ancestor = el.parentElement;

                while (ancestor && window.getComputedStyle(ancestor).position === 'static') {
                    ancestor = ancestor.parentElement;
                }

                if (ancestor) {
                    const box = ancestor.getBoundingClientRect();

                    if (box.width > 0 && box.height > 0) {
                        return box;
                    }
                }
            }

            return own;
        };

        return Array.from(document.querySelectorAll<HTMLElement>(selector))
            .map((el) => {
                const style = window.getComputedStyle(el);

                if (style.visibility === 'hidden' || style.display === 'none') {
                    return null;
                }

                // An inline target is exempt: WCAG 2.2 exempts a target in a
                // sentence, whose size is set by the text around it.
                if (style.display === 'inline' && el.tagName === 'A') {
                    return null;
                }

                const own = el.getBoundingClientRect();

                if (own.width === 0 && own.height === 0) {
                    return null;
                }

                // The skip link, and anything else visually hidden until it is
                // focused.
                if (own.width <= 2 && own.height <= 2) {
                    return null;
                }

                const box = effectiveBox(el);

                if (box.width >= minimum && box.height >= minimum) {
                    return null;
                }

                const text = (
                    el.textContent ||
                    el.getAttribute('aria-label') ||
                    el.getAttribute('name') ||
                    ''
                ).trim().replace(/\s+/g, ' ').slice(0, 40);

                return `${Math.round(box.width * 10) / 10} x ${Math.round(box.height * 10) / 10} `
                    + `<${el.tagName.toLowerCase()}${el.getAttribute('type') ? ' type=' + el.getAttribute('type') : ''}> `
                    + `"${text}"`;
            })
            .filter((entry): entry is string => entry !== null);
    }, MINIMUM);
}

test.describe('Touch targets', () => {
    test('nothing on a phone-sized screen is smaller than a fingertip', async ({ page }) => {
        test.setTimeout(180_000);

        await page.setViewportSize({ width: 375, height: 720 });

        /*
         * An unread message, so the bell has something to show.
         *
         * Without one, the dropdown's "Mark all read" button is not rendered -
         * and a control that is only on the page when there is something to
         * mark is exactly the control a test that measures the empty state
         * would never see. It was 16 pixels tall.
         */
        seedMessages(admin.email, 1, 'info');

        const problems: string[] = [];

        for (const screen of SCREENS) {
            await page.context().clearCookies();

            if (screen.as === 'staff') {
                await signIn(page, staff.email);
            } else if (screen.as === 'admin') {
                await signIn(page, admin.email);
            } else if (screen.as === 'client') {
                await signIn(page, newcomer.email);
            }

            await page.goto(screen.url, { waitUntil: 'domcontentloaded' });

            const small = await tooSmall(page);

            if (small.length) {
                problems.push(`${screen.url}\n    ${small.join('\n    ')}`);
            }
        }

        expect(
            problems.join('\n'),
            'Targets smaller than 24x24 CSS pixels. WCAG 2.2 Target Size '
                + '(Minimum) is a Level AA requirement, and a resident booking a '
                + 'slot on a phone is exactly who it is for.',
        ).toBe('');
    });
});
