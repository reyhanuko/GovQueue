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
 *
 * A client may claim more than one category, and each has its own document,
 * so each is asked for on its own: one field per claim, not one field for
 * both.
 */
test.describe('Priority reference', () => {
    test('the booking form asks for one only when a claim needs it', async ({ page }) => {
        await signIn(page, newcomer.email);
        await page.goto('/services/CERT-IND/book');

        const pwdReference = page.locator('#priority_reference_pwd');
        const pregnancyReference = page.locator('#priority_reference_pregnant');
        const pwd = page.getByRole('checkbox', { name: /person with disability/i });
        const pregnant = page.getByRole('checkbox', { name: /pregnant client/i });

        await expect(pwdReference, 'The reference field is on the page before any claim is made.').toBeHidden();
        await expect(pregnancyReference).toBeHidden();

        await pwd.check();

        await expect(pwdReference, 'Ticking a PWD claim must ask for the reference that verifies it.').toBeVisible();
        await expect(pregnancyReference, 'Ticking one claim must not ask for the other one\'s reference.').toBeHidden();

        // And it goes away again, so a client who ticks the wrong box is not
        // left with a field they have to fill in.
        await pwd.uncheck();

        await expect(pwdReference).toBeHidden();

        // Two claims, two references.
        await pwd.check();
        await pregnant.check();

        await expect(pwdReference).toBeVisible();
        await expect(pregnancyReference).toBeVisible();
    });

    test('the registration form asks for one only when a claim needs it', async ({ page }) => {
        await page.goto('/register');

        const pwdReference = page.locator('#priority_reference_pwd');
        const pwd = page.getByRole('checkbox', { name: /person with disability/i });

        await expect(pwdReference, 'The reference field is on the page before any claim is made.').toBeHidden();

        await pwd.check();

        await expect(pwdReference, 'Ticking a PWD claim must ask for the reference that verifies it.').toBeVisible();
    });

    test('a client can attach a soft copy of the document with their claim', async ({ page }) => {
        await signIn(page, newcomer.email);
        await page.goto('/services/CERT-IND/book');

        const document = page.locator('#priority_document');
        const pwd = page.getByRole('checkbox', { name: /person with disability/i });

        await expect(document, 'The document field is on the page before any claim is made.').toBeHidden();

        await pwd.check();

        await expect(document, 'A client claiming priority must be able to send the document with it.').toBeVisible();

        // And the file types the office can open.
        await expect(document).toHaveAttribute('accept', '.pdf,.jpg,.jpeg,.png');
    });
});
