import { expect, Page } from '@playwright/test';

/**
 * Shared helpers for the browser suite.
 *
 * Kept small on purpose. Each one encodes something that was true of this
 * application and would be false of most: the login field names, the fact that
 * the lobby display is unauthenticated, the exact wording of the confirmation
 * dialog.
 */

/** Sign in through the real form, so the session and redirect are exercised. */
export async function signIn(page: Page, email: string, password = 'password') {
    await page.goto('/login');

    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: /^sign in$/i }).click();

    /*
     * Waiting for the URL to leave /login, not for networkidle.
     *
     * networkidle waits for 500ms with no requests in flight, and this app
     * keeps a socket and a Livewire poll going - so "idle" either never arrives
     * or arrives at a moment unrelated to the sign-in having worked. The only
     * thing that matters is that we ended up somewhere else, which is what the
     * redirect proves.
     */
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), {
        timeout: 15_000,
    });
}

/** The number of data rows in the first table on the page. */
export async function tableRowCount(page: Page): Promise<number> {
    return page.locator('table tbody tr').count();
}

/**
 * Create a service window through the real form and return its name.
 *
 * Destructive tests use this rather than a seeded row, so they do not depend on
 * what other tests have already deleted. Without it the suite is
 * order-dependent: the test that confirms a deletion leaves nothing for the
 * test after it, and the failure looks like a broken assertion rather than a
 * broken fixture.
 */
export async function createWindow(page: Page, label = 'Disposable Window'): Promise<string> {
    // Unique per call, so a test that runs twice against a dirty database still
    // finds its own row.
    const name = `${label} ${Math.random().toString(36).slice(2, 8)}`;

    await page.goto('/admin/windows/create');
    await page.getByLabel('Window name').fill(name);
    // The code must be URL-safe and unique, so it is derived from the label's
    // random suffix rather than left blank.
    await page.getByLabel('Code').fill('W' + Math.random().toString(36).slice(2, 9).toUpperCase());
    await page.getByRole('button', { name: 'Create window' }).click();
    await page.waitForURL((url) => url.pathname.endsWith('/admin/windows'));

    return name;
}

/**
 * The row containing a given window name.
 *
 * Scoped by name rather than by index, because the row that matters is the one
 * a person would click, not whichever one happens to be first after sorting.
 */
export function windowRow(page: Page, name: string) {
    return page.locator('table tbody tr').filter({ hasText: name });
}

/**
 * A link in the admin sidebar, identified by where it goes rather than by its
 * text.
 *
 * The sidebar renders each section twice - once inside the small-screen
 * disclosure, once in the desktop rail - and on the current page the link also
 * carries a hint line. So its accessible name is "Dashboard Is the office open,
 * and what is the queue doing?", and matching on text is both brittle and
 * ambiguous between the two copies.
 *
 * Matched on the absolute href because that is what route() emits. Matching the
 * bare path instead would silently match nothing - and, with a substring
 * matcher, would match /admin when asked for /admin but also every section
 * under it.
 */
export function sidebarLink(page: Page, path: string) {
    const href = new URL(path, 'http://127.0.0.1:8123').href;

    return page.locator(`nav[aria-label="Administration"] a[href="${href}"]`);
}

export const admin = { email: 'admin@govqueue.test' };
export const staff = { email: 'staff1@govqueue.test' };

/**
 * The confirmation dialog govqueueConfirm() builds lazily on first use.
 *
 * Located by its stable hooks rather than its copy, so rewording the message
 * never breaks the test that is checking the dialog still appears.
 */
export const confirmDialog = {
    dialog: (page: Page) => page.locator('#govqueue-confirm-dialog'),
    message: (page: Page) => page.locator('#govqueue-confirm-dialog [data-gq-message]'),
    detail: (page: Page) => page.locator('#govqueue-confirm-dialog [data-gq-detail]'),
    confirm: (page: Page) => page.locator('#govqueue-confirm-dialog [data-gq-confirm]'),
    cancel: (page: Page) => page.locator('#govqueue-confirm-dialog [data-gq-cancel]'),
};

/**
 * Has the page laid out wider than the viewport?
 *
 * The direct assertion for the admin sidebar's 15rem column: a screen that has
 * been squeezed into a scrollable strip is technically "not broken" and
 * practically unusable, and nothing in the PHPUnit suite can see it.
 */
export async function hasHorizontalOverflow(page: Page): Promise<boolean> {
    return page.evaluate(() => {
        const doc = document.documentElement;

        return doc.scrollWidth > doc.clientWidth + 1;
    });
}

/** The widest element sticking out past the viewport, for a useful failure message. */
export async function overflowingElement(page: Page): Promise<string> {
    return page.evaluate(() => {
        const limit = document.documentElement.clientWidth;

        const worst = Array.from(document.querySelectorAll<HTMLElement>('body *'))
            .filter((el) => el.getBoundingClientRect().right > limit + 1)
            // Deepest-first: the leaf that actually overflows is more useful
            // than the table containing it.
            .sort((a, b) => b.getBoundingClientRect().right - a.getBoundingClientRect().right)[0];

        if (!worst) {
            return 'none';
        }

        return `${worst.tagName.toLowerCase()}${worst.className ? '.' + String(worst.className).split(' ').slice(0, 3).join('.') : ''}`
            + ` (right edge ${Math.round(worst.getBoundingClientRect().right)}px vs viewport ${limit}px)`;
    });
}
