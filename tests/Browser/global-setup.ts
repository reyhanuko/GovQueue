import { execFileSync } from 'node:child_process';

/**
 * Rebuild the browser database before the suite.
 *
 * The destructive tests really delete things - that is the point - so a suite
 * that shared seeded rows between tests would leave later tests asserting
 * against an empty table, and the failure would depend on execution order.
 *
 * A full migrate:fresh --seed runs once rather than per test, because the demo
 * seeder is the expensive part and only one reset is needed: every destructive
 * test creates the record it is going to destroy.
 *
 * The view cache is cleared first, and that is not tidiness.
 *
 * This suite asserts on rendered markup, so a stale compiled Blade view does not
 * fail a test - it produces a *plausible* wrong page that the assertions then
 * read. Observed: nine contrast tests failing against markup that had been
 * reverted hours earlier, because a compiled view survived the revert. Those
 * failures looked like real regressions and were not, which is the worst
 * possible outcome for a guard whose job is to catch real regressions.
 *
 * The cause is that Windows tooling preserves mtimes, so Laravel's view cache
 * cannot tell a file that changed underneath it from one that did not.
 */
export default function globalSetup() {
    const php = process.platform === 'win32' ? 'php.exe' : 'php';

    const env = { ...process.env, APP_ENV: 'browser' };

    execFileSync(php, ['artisan', 'view:clear', '--env=browser'], {
        cwd: process.cwd(),
        stdio: 'inherit',
        env,
    });

    execFileSync(php, ['artisan', 'migrate:fresh', '--force', '--seed', '--env=browser'], {
        cwd: process.cwd(),
        stdio: 'inherit',
        env,
    });
}
