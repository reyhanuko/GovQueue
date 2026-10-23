import { expect } from '@playwright/test';
import { admin, signIn } from './helpers';
import { test } from './fixtures';

/**
 * WCAG AA contrast, measured rather than estimated.
 *
 * "Looks too light" is not a finding you can act on, and reading hex values off a
 * palette tells you what the token says rather than what the browser paints once
 * opacity, inheritance and background compositing are applied. This asks the
 * rendered page.
 *
 * One trap worth recording, because it produced a run of nonsense results: this
 * project uses Tailwind v4, which emits colours as oklch(). A parser that only
 * understands rgb() silently reads every colour as unparseable and falls back to
 * white, which then reports "white on white, 1.00:1" for buttons that are
 * plainly white on a dark slate background. Every reported failure was the
 * checker, not the pages.
 *
 * So the colours are normalised through a canvas, which makes the browser do the
 * oklch to sRGB conversion itself rather than this file reimplementing it.
 */

const PAGES = [
    { name: 'dashboard', path: '/admin' },
    { name: 'daily slots', path: '/admin/slots' },
    { name: 'services', path: '/admin/services' },
    { name: 'holidays', path: '/admin/holidays' },
    { name: 'staff', path: '/admin/staff' },
    { name: 'windows', path: '/admin/windows' },
    { name: 'reports', path: '/admin/reports' },
    { name: 'audit', path: '/admin/audit' },
    { name: 'notifications', path: '/admin/notifications' },
];

/**
 * Runs in the page. Returns one line per failure so a red run names the element,
 * the colour, the ratio and the text - otherwise a failing contrast suite is a
 * list of numbers nobody can act on.
 */
function collectContrastFailures(): string[] {
    const canvas = document.createElement('canvas');
    canvas.width = 1;
    canvas.height = 1;

    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    if (!ctx) return ['no canvas context'];

    /**
     * Any CSS colour as bytes, by rasterising it.
     *
     * Reading back ctx.fillStyle after assigning a colour looks like the obvious
     * way to normalise, and it silently fails here: Chrome keeps the oklch() text
     * rather than converting it, so every "normalised" foreground came back
     * unusable and the checker reported white-on-white for buttons that are
     * plainly white on dark slate. Filling one pixel and reading the pixel forces
     * an actual conversion to sRGB, whatever syntax the browser was handed.
     */
    const normalise = (value: string): { rgb: [number, number, number]; a: number } | null => {
        if (!value || value === 'transparent') return null;

        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = '#000000';
        ctx.fillStyle = value;
        ctx.fillRect(0, 0, 1, 1);

        const data = ctx.getImageData(0, 0, 1, 1).data;

        return {
            rgb: [data[0], data[1], data[2]],
            a: data[3] / 255,
        };
    };

    const luminance = ([r, g, b]: [number, number, number]): number => {
        const c = (v: number) => {
            const s = v / 255;

            return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
        };

        return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b);
    };

    /** Source-over compositing of a translucent colour onto an opaque backdrop. */
    const composite = (
        fg: [number, number, number],
        alpha: number,
        bg: [number, number, number],
    ): [number, number, number] => [
        fg[0] * alpha + bg[0] * (1 - alpha),
        fg[1] * alpha + bg[1] * (1 - alpha),
        fg[2] * alpha + bg[2] * (1 - alpha),
    ];

    const ratio = (a: [number, number, number], b: [number, number, number]) => {
        const la = luminance(a);
        const lb = luminance(b);

        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    };

    /** First ancestor with a background that is not fully transparent. */
    const backdropOf = (el: Element): [number, number, number] => {
        let node: Element | null = el;

        while (node) {
            const parsed = normalise(getComputedStyle(node).backgroundColor);

            if (parsed && parsed.a > 0) return parsed.rgb;
            node = node.parentElement;
        }

        return [255, 255, 255];
    };

    const failures: string[] = [];

    for (const el of Array.from(document.querySelectorAll<HTMLElement>('body *'))) {
        const text = Array.from(el.childNodes)
            .filter((n) => n.nodeType === Node.TEXT_NODE)
            .map((n) => n.textContent ?? '')
            .join('')
            .trim();

        if (!text) continue;

        /*
         * Skipped: content marked aria-hidden is decorative by declaration - a
         * bullet between two facts, a slash in a breadcrumb - and WCAG 1.4.3
         * exempts decoration. Bullet glyphs are exactly the sort of thing that
         * scores badly on contrast while carrying no information at all, and
         * failing a suite on them trains people to ignore it.
         */
        if (el.closest('[aria-hidden="true"]')) continue;

        const cs = getComputedStyle(el);

        if (cs.visibility === 'hidden' || cs.display === 'none') continue;
        if (parseFloat(cs.opacity) === 0) continue;

        const rect = el.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) continue;

        const foreground = normalise(cs.color);
        if (!foreground) continue;

        const background = backdropOf(el);

        const effective = foreground.a < 1
            ? composite(foreground.rgb, foreground.a, background)
            : foreground.rgb;

        const value = ratio(effective, background);

        const size = parseFloat(cs.fontSize);
        const bold = parseInt(cs.fontWeight, 10) >= 700;

        // AA: 3:1 for large text (>=24px, or >=18.66px bold), 4.5:1 otherwise.
        const required = size >= 24 || (bold && size >= 18.66) ? 3 : 4.5;

        if (value < required) {
            failures.push(
                `${el.tagName.toLowerCase()}.${el.className.toString().split(/\s+/).slice(0, 3).join('.')} ` +
                    `${size}px ${cs.color} on ${cs.backgroundColor === 'rgba(0, 0, 0, 0)' ? 'inherited' : cs.backgroundColor} ` +
                    `= ${value.toFixed(2)}:1, needs ${required}:1 - "${text.slice(0, 45)}"`,
            );
        }
    }

    return failures;
}

test.describe('Colour contrast', () => {
    for (const target of PAGES) {
        test(`${target.name}: text meets WCAG AA`, async ({ page }) => {
            await signIn(page, admin.email);
            await page.goto(target.path);

            // The page must have painted before anything is measured.
            await expect(page.locator('main')).toBeVisible();

            const failures = await page.evaluate(collectContrastFailures);

            expect(
                failures,
                `${target.path}: ${failures.length} contrast failure(s)\n  ` + failures.join('\n  '),
            ).toEqual([]);
        });
    }

    /**
     * The active sidebar item must be findable without relying on a colour the
     * palette already uses elsewhere.
     */
    test('the active sidebar item differs by shape or weight, not only tint', async ({ page }) => {
        await signIn(page, admin.email);
        await page.goto('/admin/slots');

        const result = await page.evaluate(() => {
            const links = Array.from(
                document.querySelectorAll<HTMLAnchorElement>('nav[aria-label="Administration"] a[href]'),
            );

            const current = links.find((a) => a.getAttribute('aria-current') === 'page');
            const other = links.find((a) => a.getAttribute('aria-current') !== 'page');

            if (!current || !other) return null;

            const bar = getComputedStyle(current, '::before');

            return {
                weightDiffers:
                    parseInt(getComputedStyle(current).fontWeight, 10) !==
                    parseInt(getComputedStyle(other).fontWeight, 10),
                hasBar: bar.content !== 'none' && parseFloat(bar.width || '0') > 0,
            };
        });

        expect(result, 'Could not compare the active and inactive sidebar items.').not.toBeNull();

        // Deliberately no contrast ratio here: the point of the left bar is that
        // it is a shape, which ratio arithmetic cannot describe.
        expect(
            result!.weightDiffers || result!.hasBar,
            'The active sidebar item is distinguished by tint alone.',
        ).toBe(true);
    });
});