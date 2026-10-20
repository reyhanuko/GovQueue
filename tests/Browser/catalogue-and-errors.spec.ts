import { expect } from '@playwright/test';
import { admin, client, signIn, staff } from './helpers';
import { test } from './fixtures';

/**
 * What the catalogue says about a service a client cannot book.
 *
 * The card has three states. Two of them are about absence, and they used to
 * look identical: a service whose places are all taken, and a service with no
 * timetable at all. Both were described as "Fully booked for now. New slots
 * are added daily. Walk-ins are still accepted at the office."
 *
 * For a service nobody has scheduled that is three separate lies - it is not
 * full, nobody has committed to adding slots, and the walk-in line is a claim
 * the system has no per-service setting behind it.
 *
 * Checked in a browser because that is where the wording is the whole point.
 * The response is correct either way; the card is what a resident reads.
 */
test.describe('Public catalogue', () => {
    /** The card for one service, by name. */
    async function card(page: import('@playwright/test').Page, name: string) {
        return page.locator('li').filter({ hasText: name }).first();
    }

    test('a service with no schedule says so, and promises nothing', async ({ page }) => {
        await page.goto('/services');

        const unscheduled = await card(page, 'Unscheduled Service');
        await expect(unscheduled).toBeVisible();

        await expect(unscheduled.getByText('No schedule yet')).toBeVisible();

        // The three claims that were wrong.
        await expect(unscheduled.getByText('Fully booked for now')).toHaveCount(0);
        await expect(unscheduled.getByText('New slots are added daily')).toHaveCount(0);
        await expect(unscheduled.getByText(/Walk-ins are still/)).toHaveCount(0);

        // And it is not offered for booking.
        await expect(unscheduled.getByRole('link', { name: 'Book a slot' })).toHaveCount(0);
    });

    test('a service whose places are taken is still described as full', async ({ page }) => {
        await page.goto('/services');

        // The other half of the distinction. Saying "no schedule yet" here would
        // be its own lie - there is a timetable and every place in it is gone.
        const full = await card(page, 'Fully Booked Service');
        await expect(full).toBeVisible();

        await expect(full.getByText('Fully booked for now')).toBeVisible();
        await expect(full.getByText('No schedule yet')).toHaveCount(0);

        // The walk-in claim goes regardless: there is no setting behind it.
        await expect(full.getByText(/Walk-ins are still/)).toHaveCount(0);
    });

    test('no card on the page promises walk-ins for an unconfigured service', async ({ page }) => {
        await page.goto('/services');

        // Every active service is rendered here, whatever its state.
        await expect(page.getByText(/Walk-ins are still/)).toHaveCount(0);
    });

    test('a bookable service offers no client booking action to a staff member', async ({ page }) => {
        await signIn(page, staff.email);
        await page.goto('/services');

        // Booking is a client flow. Sending staff to /booking is a bare 403, so
        // the link is replaced with a label that says why.
        const bookable = await card(page, 'Certificate of Indigency');
        await expect(bookable.getByRole('link', { name: 'Book a slot' })).toHaveCount(0);
        await expect(bookable.getByText('Client accounts only')).toBeVisible();
    });

    test('a client is offered the booking link on a bookable service', async ({ page }) => {
        await signIn(page, client.email);
        await page.goto('/services');

        const bookable = await card(page, 'Certificate of Indigency');
        await expect(bookable.getByRole('link', { name: 'Book a slot' })).toBeVisible();
    });

    test('an administrator is offered no client booking action', async ({ page }) => {
        // The catalogue is public, so an administrator sees it too. Booking is a
        // client flow; showing them a Book link sent them to a bare 403.
        await signIn(page, admin.email);
        await page.goto('/services');

        await expect(page.getByRole('link', { name: 'Book a slot' })).toHaveCount(0);
    });
});

/**
 * The pages a resident or a clerk sees when something goes wrong.
 *
 * Each status here is produced for real rather than simulated - a URL that does
 * not exist, a client reaching the admin area, a POST to a GET-only route, a
 * form post with a stale token - because the point of these pages is what
 * happens when things genuinely go wrong.
 *
 * The reference code is the focus. This application holds birthdates, phone
 * numbers and disability status, so a stack trace is a privacy incident, and
 * "it broke" is the only thing somebody can report without one.
 */
test.describe('Error pages', () => {
    test('a page that does not exist shows a reference the reader can quote', async ({ page }) => {
        const response = await page.goto('/no-such-page');

        expect(response?.status()).toBe(404);

        await expect(page.getByText(/quote this reference/i)).toBeVisible();

        // The code on the page is the id the server logged. Asserted against the
        // response header rather than taken on trust, because a reference that
        // does not match the log is worse than none.
        const header = response?.headers()['x-request-id'];

        expect(header, 'The response carried no request id, so nothing can be quoted.').toBeTruthy();
        await expect(page.getByText(header!, { exact: false })).toBeVisible();
    });

    test('a client reaching the admin area sees a refusal, not an error', async ({ page }) => {
        await signIn(page, staff.email);

        const response = await page.goto('/admin');

        expect(response?.status()).toBe(403);
        await expect(page.getByText('Access denied')).toBeVisible();
        await expect(page.getByText(/quote this reference/i)).toBeVisible();
    });

    test('a request the page will not accept is explained, not dumped', async ({ page }) => {
        // A real 405: POST to a URL that only answers GET. This is the exact
        // status the broken "move" form produced for a real client.
        const response = await page.request.post('/services');

        expect(response.status()).toBe(405);

        const body = await response.text();

        expect(body).toContain('405');
        expect(body).toContain('quote this reference');

        // The thing that must never appear, in any of these pages.
        expect(body).not.toContain('vendor/laravel');
        expect(body).not.toContain('Symfony\\');
        expect(body).not.toMatch(/stack trace/i);
    });

    test('a form posted without a valid token is reported as an expired session', async ({ page }) => {
        // A real 419. No CSRF token, so the request is rejected exactly as it
        // would be when a resident leaves a form open past the session's life.
        const response = await page.request.post('/login');

        expect(response.status()).toBe(419);

        const body = await response.text();

        expect(body).toContain('419');
        expect(body).toMatch(/session/i);
        expect(body).not.toContain('vendor/laravel');
    });
});
