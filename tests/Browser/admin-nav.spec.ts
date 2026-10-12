import { expect } from '@playwright/test';
import { admin, hasHorizontalOverflow, overflowingElement, sidebarLink, signIn } from './helpers';
import { test } from './fixtures';

/**
 * The admin sidebar, and what it did to the width of everything else.
 *
 * The admin area moved from a flat row of nine tabs to a grouped 15rem
 * sidebar. That is a real improvement in findability, and it took 15rem away
 * from every admin page - including several that were already the widest
 * tables in the application.
 *
 * This is the check the PHPUnit suite structurally cannot make. Every table
 * here renders fine at any width as far as an HTTP test is concerned: the HTML
 * is valid, the assertions pass, and nothing about the response says "this is
 * unreadable on the laptop the office actually has". Only a browser with a
 * viewport can tell you that a screen is scrolling sideways.
 *
 * 1366x768 is the target, not a modern laptop. That is the resolution a
 * municipal office tends to have.
 */
test.describe('Admin sidebar', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, admin.email);
    });

    const adminScreens: Array<[string, string]> = [
        ['/admin', 'Dashboard'],
        ['/admin/slots', 'Daily slots'],
        ['/admin/reports', 'Reports'],
        ['/admin/audit', 'Audit trail'],
        ['/admin/staff', 'Staff'],
        ['/admin/windows', 'Service windows'],
        ['/admin/services', 'Services'],
        ['/admin/holidays', 'Holidays'],
        ['/admin/notifications', 'Notification log'],
    ];

    for (const [path, label] of adminScreens) {
        test(`${label} does not scroll sideways at 1366px`, async ({ page }) => {
            await page.goto(path);
            await expect(page.locator('main')).toBeVisible();

            expect(
                await hasHorizontalOverflow(page),
                `${path} scrolls horizontally at 1366px. Worst element: `
                    + `${await overflowingElement(page)}`,
            ).toBe(false);
        });
    }

    test('the sidebar is a named navigation landmark', async ({ page }) => {
        // There are now two navigation regions on an admin page. A screen
        // reader listing "navigation" twice with nothing to tell them apart is
        // worse than one region.
        await page.goto('/admin');

        await expect(page.locator('nav[aria-label="Administration"]')).toHaveCount(1);
        await expect(page.locator('nav[aria-label="Main navigation"]')).toHaveCount(1);
    });

    test('it marks where you are, and only there', async ({ page }) => {
        await page.goto('/admin/windows');
        await expect(page.locator('main')).toBeVisible();

        const current = page.locator('main [aria-current="page"]');
        const labels = (await current.allInnerTexts()).map((t) => t.trim());

        expect(labels.some((l) => l.startsWith('Windows'))).toBe(true);

        // Marking every link at once would be no better than marking none.
        expect(labels.some((l) => l.startsWith('Audit trail'))).toBe(false);
    });

    test('every section is reachable', async ({ page }) => {
        const sections = [
            '/admin',
            '/admin/slots',
            '/admin/services',
            '/admin/windows',
            '/admin/holidays',
            '/admin/staff',
            '/admin/reports',
            '/admin/audit',
            '/admin/notifications',
        ];

        await page.goto('/admin');

        for (const path of sections) {
            // Present in both the small-screen disclosure and the desktop rail;
            // at least one copy has to exist, and the destination has to be the
            // section and not somewhere else entirely.
            expect(
                await sidebarLink(page, path).count(),
                `No sidebar link points at ${path}.`,
            ).toBeGreaterThan(0);
        }
    });

    test('a narrow screen collapses the sidebar instead of squeezing it', async ({ page }) => {
        // Below lg the rail would push the content a long way down, so the
        // groups collapse into a disclosure. Same markup, no JavaScript.
        await page.setViewportSize({ width: 800, height: 900 });
        await page.goto('/admin');

        const nav = page.locator('nav[aria-label="Administration"]');
        const disclosure = nav.locator('summary');

        await expect(disclosure).toBeVisible();
        await expect(disclosure).toContainText('Administration menu');

        // Closed by default: at this width an always-open rail would push the
        // dashboard heading below the fold, which is worse than one extra click.
        await expect(sidebarLink(page, '/admin/reports').first()).toBeHidden();

        await disclosure.click();
        await expect(sidebarLink(page, '/admin/reports').first()).toBeVisible();
    });

    test('a past day is explained rather than silently read-only', async ({ page }) => {
        // The other thing the sidebar work touched. Past slots are view-only, and
        // a view-only screen that gives no reason reads as broken.
        const yesterday = new Date(Date.now() - 86_400_000).toISOString().slice(0, 10);

        await page.goto(`/admin/slots?date=${yesterday}`);
        await expect(page.locator('main')).toBeVisible();

        await expect(page.getByText(/view only/i).first()).toBeVisible();

        // And it offers no way to change anything: not a disabled control,
        // which would invite a click that does nothing, but no control at all.
        await expect(page.locator('[name="intent"]')).toHaveCount(0);
    });
});
