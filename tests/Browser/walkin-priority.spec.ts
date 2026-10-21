import { expect, Locator, Page } from '@playwright/test';
import { signIn, staff } from './helpers';
import { test } from './fixtures';

/**
 * The walk-in panel's statutory priority controls.
 *
 * The claim-versus-grant distinction is the whole point of this screen, and it
 * is a distinction made in the browser: the ticket is issued and appears in the
 * queue, and the queue does NOT move. Asserting that from PHP is possible but
 * indirect - it would be proving an ordering invariant rather than what a clerk
 * sees when they press the button and read the sentence under it.
 *
 * So: the panel's behaviour is checked here, and the ordering itself is left to
 * QueueOrderingServiceTest, which can be exhaustive about it for free.
 */
test.describe('Walk-in priority panel', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, staff.email);
        await page.goto('/staff');

        // The console is pinned to the clerk's own window now. If this ever
        // stops being true, the rest of these tests would be measuring the wrong
        // queue, so it is asserted rather than assumed.
        await expect(page.getByText(/limited to your assigned window/i)).toBeVisible();
    });

    /**
     * The walk-in form, opened and ready.
     *
     * Anchored on the form's own wire:submit rather than on a chain of parent
     * selectors from the heading. That attribute is what identifies this form to
     * Livewire, so it is the one thing in the markup guaranteed to stay put - and
     * a locator that breaks whenever a wrapper div is added makes a suite people
     * stop trusting.
     */
    async function openWalkInPanel(page: Page) {
        await page.getByRole('button', { name: 'Walk-in' }).click();

        const form = page.locator('form[wire\\:submit="createWalkIn"]');
        await expect(form).toBeVisible();

        return form;
    }

    /**
     * A statutory category's checkbox.
     *
     * By `value`, not by label text: "Senior Citizen" also appears in the
     * service dropdown ("Senior Citizen ID") and in the explanatory paragraph,
     * so a text locator matches three elements and the test fails on ambiguity
     * rather than on behaviour.
     */
    function claimBox(form: Locator, value: string) {
        return form.locator(`input[type="checkbox"][value="${value}"]`);
    }

    test('it offers the three statutory categories with their legal basis', async ({ page }) => {
        const panel = await openWalkInPanel(page);

        for (const value of ['senior_citizen', 'pwd', 'pregnant']) {
            await expect(claimBox(panel, value)).toBeVisible();
        }

        // Each category names what it rests on, next to its checkbox. A clerk is
        // being asked to make a statutory decision, and "which law is that?" is a
        // fair question. Exact matches, because "RA 9994" also appears in the
        // explanatory paragraph above the boxes.
        await expect(panel.getByText('RA 9994', { exact: true })).toBeVisible();
        await expect(panel.getByText('RA 10754', { exact: true })).toBeVisible();

        // The consequence is stated on the form, not only in the docs. A clerk
        // who does not read the codebase needs to know a tick box is not a
        // decision.
        await expect(panel.getByText(/does not move anybody/i)).toBeVisible();
    });

    test('a reference is asked for only where one cannot be inferred', async ({ page }) => {
        const panel = await openWalkInPanel(page);
        const reference = panel.getByLabel(/pwd reference/i);

        // A date of birth can be looked at. An ID number and a referral cannot.
        await claimBox(panel, 'senior_citizen').check();
        await expect(reference).toHaveCount(0);

        await claimBox(panel, 'pwd').check();
        await expect(reference).toBeVisible();

        await claimBox(panel, 'pwd').uncheck();
        await expect(reference).toHaveCount(0);
    });

    test('a PWD claim with no reference is refused, and issues no ticket', async ({ page }) => {
        const panel = await openWalkInPanel(page);

        await panel.getByLabel('Full name').fill('Nina Dizon');
        await claimBox(panel, 'pwd').check();
        await panel.getByRole('button', { name: /issue ticket/i }).click();

        // A clear message, and the panel stays open with the typed name intact -
        // making somebody retype a name to add one field is how data gets lost.
        await expect(page.getByText(/reference/i).first()).toBeVisible();
        await expect(panel.getByLabel('Full name')).toHaveValue('Nina Dizon');
    });

    test('issuing a claim says it was recorded, not granted', async ({ page }) => {
        const panel = await openWalkInPanel(page);

        await panel.getByLabel('Full name').fill('Pedro Ramos');
        await claimBox(panel, 'senior_citizen').check();
        await panel.getByRole('button', { name: /issue ticket/i }).click();

        // The sentence matters. "Recorded" and "granted" are different promises,
        // and a clerk who reads only the first line would tell a client they
        // have been moved up the queue when they have not.
        await expect(page.getByText(/priority claim recorded/i).first()).toBeVisible();
        await expect(page.getByText(/queue is unchanged until then/i).first()).toBeVisible();
    });

    test('a claimed walk-in does not move ahead of somebody already waiting', async ({ page }) => {
        /*
         * The invariant, seen from the operator's chair.
         *
         * The fixtures put two ordinary clients ahead in the queue. Issuing a
         * senior-citizen walk-in must leave them exactly where they are, because
         * verification happens afterwards and reorders then - not now.
         *
         * Read as ticket numbers rather than as whole rows: a name and a time
         * are the same either way, and only the number can go missing, which is
         * the failure being ruled out.
         */
        const queueNumbers = () =>
            page.locator('ol > li span.font-black').allInnerTexts();

        const before = await queueNumbers();

        expect(
            before.length,
            'The fixture queue is empty, so this test would pass whatever happened.',
        ).toBeGreaterThanOrEqual(2);

        const panel = await openWalkInPanel(page);
        await panel.getByLabel('Full name').fill('Alma Santos');
        await claimBox(panel, 'senior_citizen').check();
        await panel.getByRole('button', { name: /issue ticket/i }).click();

        await expect(page.getByText(/priority claim recorded/i).first()).toBeVisible();

        const after = await queueNumbers();

        // The people already waiting are unmoved and in the same order. The
        // walk-in joins behind them.
        expect(after.slice(0, before.length)).toEqual(before);
        expect(after.length).toBeGreaterThan(before.length);
    });

    /**
     * A guard with no PHPUnit coverage at all, found by this suite.
     *
     * BookingService refuses a second walk-in ticket for a name already waiting
     * for the same service, and the console turns that into an inline message
     * naming the existing ticket. Nothing in tests/ mentions it - which is how
     * the test below was originally written reusing a name from an earlier test
     * and failing for a reason three tests away.
     *
     * It is worth a test of its own because it is the difference between one
     * person in the queue twice and one, and because the override is a deliberate
     * escape hatch: a guard with no way past it would be ignored by staff, and a
     * guard with no way to see what it caught would be turned off.
     */
    test('a second ticket for a name already waiting is refused, not issued', async ({ page }) => {
        const panel = await openWalkInPanel(page);

        await panel.getByLabel('Full name').fill('Corazon Bautista');
        await panel.getByRole('button', { name: /issue ticket/i }).click();
        await expect(page.getByText(/issued for/i).first()).toBeVisible();

        const afterFirst = await page.locator('ol > li span.font-black').count();

        const second = await openWalkInPanel(page);
        await second.getByLabel('Full name').fill('Corazon Bautista');
        await second.getByRole('button', { name: /issue ticket/i }).click();

        /*
         * Read from the page, not from the form.
         *
         * The refusal surfaces as a role="alert" banner in the console's shared
         * status region rather than inside the walk-in form. That is the right
         * place for it - the form keeps the typed name so the override can be
         * ticked without retyping - but it means the message is a sibling of the
         * form, not a descendant.
         */
        const alert = page.getByRole('alert').filter({ hasText: /already has a waiting walk-in/i });

        // The message names the ticket already open, so the clerk can look at it
        // rather than guess which of nine waiting clients is meant.
        await expect(alert).toBeVisible();
        await expect(alert).toContainText(/W-\d+/);

        // Nothing was added to the queue.
        expect(await page.locator('ol > li span.font-black').count()).toBe(afterFirst);

        // And the way past it is offered explicitly, with a stated consequence -
        // not by the clerk discovering the form simply does not work.
        const override = second.getByRole('checkbox', { name: /different person/i });

        await expect(override).toBeVisible();
        await override.check();
        await second.getByRole('button', { name: /issue ticket/i }).click();

        await expect(page.getByText(/issued for/i).first()).toBeVisible();
        expect(await page.locator('ol > li span.font-black').count()).toBe(afterFirst + 1);
    });

    test('the panel forgets the previous walk-in', async ({ page }) => {
        /*
         * Leaving the previous client's categories pre-ticked would grant
         * RA 9994 priority to whoever arrived next without anybody asking.
         */
        const panel = await openWalkInPanel(page);

        /*
         * A name nobody in this suite has used.
         *
         * The console refuses a second walk-in ticket for a name that is already
         * waiting for the same service, and says so with the ticket number. That
         * is good behaviour - but it means reusing a name across tests makes the
         * ticket silently not issue, and the test fails on a missing success
         * message while the real cause is three tests earlier.
         */
        await panel.getByLabel('Full name').fill('Remedios Villanueva');
        await claimBox(panel, 'pwd').check();
        await panel.getByLabel(/pwd reference/i).fill('PWD-12345');
        await panel.getByRole('button', { name: /issue ticket/i }).click();

        await expect(page.getByText(/issued for/i).first()).toBeVisible();

        const reopened = await openWalkInPanel(page);

        // The boxes are the risk, not the text: a pre-ticked category would
        // grant RA 9994 priority to whoever arrived next, unasked.
        await expect(claimBox(reopened, 'pwd')).not.toBeChecked();
        await expect(reopened.getByLabel('Full name')).toHaveValue('');
    });

    /**
     * What a clerk sees when a staff action fails. Hypothesis #2, answered.
     *
     * The staff console is the one screen where a thrown exception lands on
     * somebody who cannot fix it and cannot leave. Most actions here catch their
     * own exceptions and set `$this->error`, which is the right pattern and is
     * what the tests above exercise. This covers what happens when one does not.
     *
     * Livewire intercepts any failed component response and dumps the whole
     * response body into a full-screen `<dialog id="livewire-error">` iframe,
     * prepended to `<body>` with page scrolling disabled. That behaviour is in
     * the framework, not in this codebase, so nothing in the source here
     * mentions it and no HTTP-level test observes it.
     *
     * The response body is whatever Laravel rendered. With APP_DEBUG on - which
     * is what .env.example ships - that is the debug page, carrying the request
     * context including full model JSON: a client's name, birthdate, phone
     * number and disability status.
     *
     * That leak is a known and documented risk here - README.md lists
     * `APP_DEBUG=false` as a go-live requirement for exactly this reason, and
     * bootstrap/app.php goes out of its way to suppress the debug page for a
     * database failure specifically. So this is not a hole in a control that
     * does not exist.
     *
     * What the README does not say is that the staff console has its own,
     * separate path to the same debug page: a Livewire action that throws does
     * not navigate, it swaps the response body into a full-screen dialog on the
     * page a clerk is looking at. Someone who knows the APP_DEBUG rule and is
     * thinking about "what a visitor sees if a page 500s" would not connect the
     * two.
     *
     * The 500 below is served by the test, not by a deliberately broken app, so
     * this asserts the client half - which is the half that is invisible until
     * you look at it - without putting a fault into the application.
     */
    test('a failed action covers the console with Livewire\'s error overlay', async ({ page }) => {
        const panel = await openWalkInPanel(page);

        /*
         * Installed after the page has loaded, not before.
         *
         * The console mounts its Livewire components over the same endpoint, so
         * a blanket intercept 500s the page load itself and the panel never
         * opens. The fault under test is the failed *submission*, so that is the
         * only request intercepted.
         */
        await page.route(/\/livewire-[^/]+\/update$/, async (route) => {
            await route.fulfill({
                status: 500,
                contentType: 'text/html',
                // Enough of Laravel's debug page for the leak to be visible: the
                // model JSON is the part that matters, not the stack frames.
                body: '<html><body><pre>SQLSTATE: connection refused\n'
                    + '{"client":{"name":"Ana Dela Cruz","birthdate":"1958-04-02",'
                    + '"phone":"0917 555 0142","claims_pwd":true}}</pre></body></html>',
            });
        });

        await panel.getByLabel('Full name').fill('Bartolome Uy');
        await panel.getByRole('button', { name: /issue ticket/i }).click();

        /*
         * Asserted as documented behaviour rather than as a bug, because the
         * framework does this by design and the choice to leave it is the
         * project's. What is NOT a choice is being unaware of it.
         *
         * If this ever changes - Livewire gains a configurable handler, or the
         * app registers one - this test fails and says why, which is the point
         * of writing it down.
         */
        const overlay = page.locator('dialog#livewire-error');

        await expect(overlay).toBeAttached();

        // The console underneath is unreachable: the overlay is a modal dialog
        // and the page is set to stop scrolling.
        await expect(overlay.locator('iframe')).toHaveCount(1);

        /*
         * The part that matters.
         *
         * The overlay renders the server's response body verbatim into an
         * iframe on the same origin - not sanitised, not summarised. So whatever
         * Laravel put in that body is now on the clerk's screen, on a monitor
         * in a public office, until they click it away. With APP_DEBUG on, that
         * body is the debug page, and the debug page includes the request
         * context.
         */
        const leaked = await overlay.locator('iframe').contentFrame();

        await expect(leaked!.getByText(/0917 555 0142/)).toBeAttached();

        const locked = await page.evaluate(() => getComputedStyle(document.body).overflow);

        expect(locked, 'The clerk can still scroll the console behind the overlay.').toBe('hidden');
    });
});
