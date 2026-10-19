import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests for the interactions the HTTP suite cannot see.
 *
 * Every defect these exist for was invisible to 1135 passing tests and was
 * found by a person clicking: a submit button that stayed disabled after a
 * cancelled confirmation, delete dialogs that appeared to be missing because
 * the second click hit a dead button, success messages that never appeared for
 * the same reason. All of them were script bugs or CSS bugs - the kind a feature
 * test asserts nothing about.
 *
 * What this suite is NOT:
 *   - a second copy of the business-logic tests. Those run in PHP, far faster,
 *     and cover far more. Anything that can be asserted without a browser is
 *     asserted there instead.
 *   - journey coverage. Ten spec files, 94 tests, each encoding a check that had
 *     been deferred three times because manual verification keeps not happening.
 *
 * The environment is disposable. It rebuilds from seed before every run, against
 * its own database, so a browser run never touches the dev data or the
 * database the PHPUnit suite uses.
 */
export default defineConfig({
    testDir: './tests/Browser',

    // Rebuild the browser database once, before the first test. The destructive
    // tests really delete things - that is the point - so a shared seeded state
    // would leave later tests asserting against an empty table.
    globalSetup: './tests/Browser/global-setup.ts',

    /*
     * A hung locator should fail the test, not the run.
     *
     * Raised from 30s because this suite does not get the machine to itself: it
     * is serial, and it runs alongside a dev server, a dev queue worker, Vite and
     * MySQL. Under that load the tests that sign in and then open a data-heavy
     * page were failing at exactly 30.3s - the test budget, not the locator -
     * while passing in seconds when the machine was idle.
     *
     * This is headroom for a busy machine, not a licence to be slow. A test that
     * fails on the clock is worse than a slow one, because it teaches people to
     * re-run instead of to read.
     */
    timeout: 60_000,
    expect: { timeout: 7_000 },

    // Serial on purpose.
    //
    // These tests share one database and one seeded queue. Running them in
    // parallel would have them issuing walk-ins and calling clients out from
    // under each other, and a failure would then depend on which worker ran
    // first - the worst possible property for a test suite.
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,

    reporter: process.env.CI ? [['github'], ['list']] : [['list']],

    use: {
        baseURL: 'http://127.0.0.1:8123',

        // Assert on what a person would actually see, not on a fragment of
        // markup that happens to contain the text.
        //
        // 15s for a click: 10s was below what the login form needed when the
        // machine was busy, and a click that times out fails with a screenshot
        // of a perfectly ordinary button, which is a misleading report.
        actionTimeout: 15_000,
        navigationTimeout: 30_000,

        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',

        // 1366x768 is the screen a municipal office actually has. Chosen over a
        // desktop default precisely because the admin sidebar narrowed the
        // content column and wide tables are the thing most likely to break.
        viewport: { width: 1366, height: 768 },
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 768 } },
        },
    ],

    webServer: {
        /*
         * Starts via the npm script rather than `php artisan serve` directly,
         * because the script removes public/hot first.
         *
         * `npm run dev` writes that file, and while it exists Laravel's @vite
         * points every page at the dev server instead of the build manifest. If
         * the dev server is not running, the page loads with NO JavaScript at
         * all - no Livewire, no Alpine, no confirmation dialog - while still
         * rendering its HTML and looking, at a glance, like an application with
         * styling problems.
         *
         * That is worth knowing independently of the tests: a stale public/hot
         * on a deployment serves a queue system whose every button is inert,
         * and the only symptom in the browser is console errors.
         *
         * Removing it here means these tests exercise the same built assets
         * production serves, and do not quietly depend on someone having left a
         * dev server running. Re-running `npm run dev` puts the file back.
         */
        command: 'npm run serve:browser',
        url: 'http://127.0.0.1:8123',

        /*
         * Reuse a server that is already up locally, never in CI.
         *
         * This was false, which is the safe answer in general: it stops a run
         * testing whatever the developer happens to have listening. In practice
         * it meant that any run which ended without its server shutting down
         * left an orphan on 8123, and every subsequent run refused to start with
         * "port is already used" until it was killed by hand. That happened four
         * times in one session, and it needed a local override file to work
         * around - which is the signal that the committed config is wrong.
         *
         * CI stays false, because a stale server there would silently test
         * yesterday's build.
         *
         * The residual risk is a developer with something else of their own on
         * 8123. That port is dedicated to this suite - the dev server is on 8000 -
         * so in practice the thing being reused is this suite's own server.
         */
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
        env: {
            APP_ENV: 'browser',
        },
    },
});
