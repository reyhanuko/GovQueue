import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { expect } from '@playwright/test';
import { admin, confirmDialog, createWindow, signIn, tableRowCount, windowRow } from './helpers';
import { test } from './fixtures';

/**
 * The confirmation dialog, and the double-submit guard underneath it.
 *
 * This is the defect class that started the browser suite.
 *
 * app.js disabled a form's submit controls on every `submit` event, as a
 * double-post guard. It also ran for submissions that were then CANCELLED -
 * which is what every confirmation dialog does, by returning false. So the
 * button stayed disabled for the rest of the page's life.
 *
 * The consequences were reported as three unrelated bugs:
 *   - "remove a slot, cancel it, and the button stays disabled"
 *   - "there is no confirmation modal when deleting a window"
 *   - "no success message when deleting a template"
 *
 * All three were the same line of JavaScript. The dialog and the flashes were
 * present in the HTML throughout; the second click simply hit a dead button, so
 * to a person the dialog looked as though it had never worked at all.
 *
 * None of it was visible to the PHPUnit suite. The endpoint returned 200, the
 * session had the right flash key, the markup had the right attributes. Only a
 * browser knows that the button the person clicks is `disabled`.
 *
 * Every test creates the window it is about to destroy, so nothing here depends
 * on what an earlier test left behind.
 */
test.describe('Confirmation dialog', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, admin.email);
    });

    test('a destructive action asks before it does anything', async ({ page }) => {
        const name = await createWindow(page);
        await page.goto('/admin/windows');

        await windowRow(page, name).getByRole('button', { name: 'Delete' }).click();

        await expect(confirmDialog.dialog(page)).toBeVisible();

        // The specific window is named, so a person can tell which row they are
        // about to destroy. "Delete this window?" alone is useless in a table.
        await expect(confirmDialog.message(page)).toContainText('Delete this window?');
        await expect(confirmDialog.detail(page)).toContainText(name);
    });

    test('focus starts on Cancel, so Enter cannot destroy anything', async ({ page }) => {
        const name = await createWindow(page);
        await page.goto('/admin/windows');

        const rowsBefore = await tableRowCount(page);

        await windowRow(page, name).getByRole('button', { name: 'Delete' }).click();
        await expect(confirmDialog.dialog(page)).toBeVisible();

        // A dialog that opens with the destructive button focused means one
        // stray Enter keypress deletes the record.
        await expect(confirmDialog.cancel(page)).toBeFocused();

        await page.keyboard.press('Enter');
        await expect(confirmDialog.dialog(page)).toBeHidden();

        // Nothing was destroyed. Asserted on the row count rather than on the
        // absence of a message, because "no error appeared" is also exactly what
        // a submit that silently did nothing looks like.
        await page.reload();
        expect(await tableRowCount(page)).toBe(rowsBefore);
        await expect(windowRow(page, name)).toHaveCount(1);
    });

    test('Escape and a backdrop click both cancel without acting', async ({ page }) => {
        const name = await createWindow(page);
        await page.goto('/admin/windows');

        const rowsBefore = await tableRowCount(page);

        await windowRow(page, name).getByRole('button', { name: 'Delete' }).click();
        await expect(confirmDialog.dialog(page)).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(confirmDialog.dialog(page)).toBeHidden();

        // Same again, closed by clicking outside the panel rather than a button.
        await windowRow(page, name).getByRole('button', { name: 'Delete' }).click();
        await expect(confirmDialog.dialog(page)).toBeVisible();
        await page.mouse.click(5, 5);
        await expect(confirmDialog.dialog(page)).toBeHidden();

        await page.reload();
        expect(await tableRowCount(page)).toBe(rowsBefore);
    });

    /**
     * The regression itself, and the reason this file exists.
     *
     * Before the fix, Cancel left the button disabled, so the second click did
     * nothing at all. From a person's chair the dialog appeared once and was
     * then simply "not working" - which is exactly how it was reported, as a
     * missing modal rather than as a dead button.
     */
    test('the button still works after a cancel, and asks again', async ({ page }) => {
        const name = await createWindow(page);
        await page.goto('/admin/windows');

        const remove = windowRow(page, name).getByRole('button', { name: 'Delete' });

        await remove.click();
        await expect(confirmDialog.dialog(page)).toBeVisible();
        await confirmDialog.cancel(page).click();
        await expect(confirmDialog.dialog(page)).toBeHidden();

        // The whole point: the button is live again, and asking a second time
        // produces a second dialog rather than submitting silently.
        await expect(remove).toBeEnabled();

        await remove.click();
        await expect(confirmDialog.dialog(page)).toBeVisible();
    });

    test('confirming carries the action out and says so', async ({ page }) => {
        const name = await createWindow(page);
        await page.goto('/admin/windows');

        const rowsBefore = await tableRowCount(page);

        await windowRow(page, name).getByRole('button', { name: 'Delete' }).click();
        await confirmDialog.confirm(page).click();

        // The row is gone.
        await expect(windowRow(page, name)).toHaveCount(0);
        expect(await tableRowCount(page)).toBe(rowsBefore - 1);

        /*
         * Two confirmations, deliberately.
         *
         * The overlay toast is the one a person sees after pressing Delete at
         * the bottom of a long table, because back() restores their scroll
         * position. The inline block is the one that survives if the script has
         * not loaded - `x-show` means an unstyled toast is not a toast. "Did that
         * save?" is a question an administrator asks twice.
         */
        await expect(page.getByText(/deleted/i).first()).toBeVisible();
        await expect(page.locator('main [role="status"]').first()).toBeVisible();
    });

    test('each row asks about its own record, not the last one clicked', async ({ page }) => {
        // The detail line is built from the specific row's data, so a dialog
        // left over from a previous click would name the wrong record - the
        // failure mode of a single shared dialog with an accumulated callback.
        const first = await createWindow(page, 'First Window');
        const second = await createWindow(page, 'Second Window');

        await page.goto('/admin/windows');

        await windowRow(page, first).getByRole('button', { name: 'Delete' }).click();
        await expect(confirmDialog.dialog(page)).toBeVisible();
        await confirmDialog.cancel(page).click();
        await expect(confirmDialog.dialog(page)).toBeHidden();

        await windowRow(page, second).getByRole('button', { name: 'Delete' }).click();
        await expect(confirmDialog.detail(page)).toContainText(second);
        await expect(confirmDialog.detail(page)).not.toContainText(first);
    });

    /**
     * A static check rather than a page check, and deliberately so.
     *
     * Checking rendered pages cannot cover this: a screen with nothing in its
     * table renders no destructive form at all, so the assertion passes for the
     * wrong reason, and a screen that only ever has rows in production is never
     * exercised at all. Reading the templates answers the real question - does
     * ANY destructive form anywhere skip a confirmation - and it fails the build
     * for a form that does not exist yet, rather than waiting for somebody to
     * create the data for it.
     *
     * Two confirmation styles are accepted:
     *
     *   - the shared modal, via govqueueConfirm(). Used throughout the admin
     *     area, where rows are destructive and the record is not the reader's.
     *   - an inline two-step reveal, where a button is replaced by the real
     *     submit control. Used for the client's own "cancel appointment", where
     *     a modal is heavier than the decision deserves.
     *
     * Either counts. What does not count is a single click that destroys
     * something with no second beat at all - that is what reintroduces the
     * dead-button bug with no other symptom.
     */
    test('every destructive form in the codebase asks for confirmation first', () => {
        const templates: string[] = [];

        const walk = (dir: string) => {
            for (const entry of readdirSync(dir)) {
                const path = join(dir, entry);

                if (statSync(path).isDirectory()) {
                    walk(path);
                } else if (path.endsWith('.blade.php')) {
                    templates.push(path);
                }
            }
        };

        walk('resources/views');

        expect(templates.length, 'No templates found - the walk is broken.').toBeGreaterThan(0);

        const offenders: string[] = [];
        let destructiveForms = 0;

        for (const path of templates) {
            const source = readFileSync(path, 'utf8');

            // Split on the opening tag so each form is judged on its own. A
            // whole-file substring test would let one form's confirmation
            // excuse another form's absence, which is exactly the bug.
            const forms = source.split(/<form\b/).slice(1);

            forms.forEach((body, index) => {
                // Stop at this form's closing tag, so a DELETE spoof further
                // down the page is not credited to it.
                const form = body.split('</form>')[0];

                if (!/@method\(\s*['"]DELETE['"]\s*\)/.test(form)) {
                    return;
                }

                destructiveForms++;

                /*
                 * The opening tag is everything before the first control or
                 * Blade directive after it.
                 *
                 * NOT split('>') - which is the obvious way and is wrong: a
                 * Blade action like `route('x', $model->id)` puts a `>` in the
                 * middle of the attribute value, so the "tag" ends halfway
                 * through the URL and an onsubmit further down is never seen.
                 * That produced a false positive here, which is the worst kind
                 * of failure for a static check: it cries wolf once and then
                 * gets disabled.
                 */
                const boundary = form.search(/@(?:csrf|method)\b|<(?:button|input|select|textarea|div|template|table|ul|ol)\b/);
                const openTag = boundary === -1 ? form.slice(0, 500) : form.slice(0, boundary);

                const hasModal = openTag.includes('govqueueConfirm');

                // An inline reveal: state that starts closed, a button that
                // opens it, and a real submit control inside.
                const hasInlineReveal =
                    /x-data=\"\{[^}]*open:\s*false/.test(form) && /<button[^>]*type=\"submit\"/.test(form);

                if (hasModal || hasInlineReveal) {
                    return;
                }

                offenders.push(
                    `${path} (form #${index + 1})\n`
                    + `      <form${openTag.replace(/\s+/g, ' ').trimEnd()}...`,
                );
            });
        }

        expect(
            destructiveForms,
            'No destructive forms were found at all - the detector is broken, not the codebase.',
        ).toBeGreaterThan(5);

        expect(
            offenders,
            'A destructive form has no confirmation of any kind. Clicking it once destroys the '
                + 'record, and if it later grows an onsubmit that cancels, its button will be left '
                + 'permanently disabled by the double-submit guard.',
        ).toEqual([]);
    });
});
