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
 */
export default function globalSetup() {
    const php = process.platform === 'win32' ? 'php.exe' : 'php';

    execFileSync(php, ['artisan', 'migrate:fresh', '--force', '--seed', '--env=browser'], {
        cwd: process.cwd(),
        stdio: 'inherit',
        env: { ...process.env, APP_ENV: 'browser' },
    });
}
