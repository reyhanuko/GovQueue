import { expect } from '@playwright/test';
import { staff, signIn } from './helpers';
import { test } from './fixtures';

/**
 * An error page has to know who is signed in.
 *
 * The framework's own 404 and 405 are rendered outside the route's middleware,
 * so the session was never started - and the navigation on the error page
 * showed a signed-in visitor as a guest, offering them a "Sign in" link for an
 * account they were already signed in to. Going anywhere else put them back.
 *
 * It was the same session cookie on the same request; the page just could not
 * read it.
 *
 * This cannot be tested from PHP. actingAs() sets the guard's user directly,
 * so the navigation is right in a feature test whether or not the session
 * exists - and the bug is precisely that the session did not exist. Only a
 * browser, which has a session and nothing else, can tell the difference.
 */
test.describe('Error pages', () => {
    test('a signed-in visitor is still signed in on a 404', async ({ page }) => {
        await signIn(page, staff.email);

        await page.goto('/no-such-page-at-all');

        await expect(page.getByText('404')).toBeVisible();

        await expect(
            page.getByRole('button', { name: 'Sign out' }),
            'The 404 page shows a signed-in visitor as a guest, with a link to sign in again.',
        ).toBeVisible();

        await expect(page.getByRole('link', { name: 'Sign in' })).toHaveCount(0);
    });

    test('a signed-in visitor is still signed in on a 405', async ({ page }) => {
        await signIn(page, staff.email);

        // A route that exists, but not for this method. A stale link produces
        // exactly this.
        await page.goto('/logout');

        await expect(page.getByText('405')).toBeVisible();

        await expect(
            page.getByRole('button', { name: 'Sign out' }),
            'The 405 page shows a signed-in visitor as a guest, with a link to sign in again.',
        ).toBeVisible();
    });

    test('a visitor with no account is still offered the sign-in', async ({ page }) => {
        await page.goto('/no-such-page-at-all');

        await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible();
    });
});
