import { expect } from '@playwright/test';
import { newcomer, signIn } from './helpers';
import { test } from './fixtures';

/**
 * A supporting reference is asked for only where one cannot be inferred.
 *
 * The rule is the same in three places - the booking form, the registration
 * form, and the staff walk-in panel - and it is this: a senior citizen claim
 * is recognised from the date of birth on file and needs no reference, while a
 * PWD or pregnancy claim cannot be inferred from anything and does.
 *
 * The booking form ignored the rule and showed the field to everybody who
 * opened it. The registration form was worse: it had x-model and x-show on the
 * fieldset and no scope for either to live in, so `pwd` was undefined, the
 * expression threw, and the field never actually hid. Written, and not working.
 *
 * Only a browser can see this. The server refuses a PWD or pregnancy claim
 * without a reference either way, so whether the field is on the page is
 * invisible to the PHPUnit suite - which is exactly why it stayed broken.
 */
test.describe('Priority reference', () => {
    test('the booking form asks for one only when a claim needs it', async ({ page }) => {
        await signIn(page, newcomer.email);
        await page.goto('/services/CERT-IND/book');

        const reference = page.locator('#priority_reference');
        const pwd = page.getByLabel(/person with disability/i);

        await expect(reference, 'The reference field is on the page before any claim is made.').toBeHidden();

        await pwd.check();

        await expect(reference, 'Ticking a PWD claim must ask for the reference that verifies it.').toBeVisible();

        // And it goes away again, so a client who ticks the wrong box is not
        // left with a field they have to fill in.
        await pwd.uncheck();

        await expect(reference).toBeHidden();
    });

    test('the registration form asks for one only when a claim needs it', async ({ page }) => {
        await page.goto('/register');

        const reference = page.locator('#priority_reference');
        const pwd = page.getByLabel(/person with disability/i);

        await expect(reference, 'The reference field is on the page before any claim is made.').toBeHidden();

        await pwd.check();

        await expect(reference, 'Ticking a PWD claim must ask for the reference that verifies it.').toBeVisible();
    });
});
