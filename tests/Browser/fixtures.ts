import { test as base, expect } from '@playwright/test';

/**
 * A test object with external requests blocked.
 *
 * Every page loads its typeface from fonts.bunny.net. In a sandboxed or
 * offline environment that request hangs rather than failing fast, so
 * `page.goto()` waits for the `load` event until it times out - twenty seconds
 * per test, with the real assertion never reached. It also makes the suite
 * dependent on a third party being up, which is not something a test of a queue
 * number should care about.
 *
 * Blocking non-localhost requests fixes both, and is the honest thing for a
 * browser suite to do anyway: a test that depends on reaching the internet is a
 * test that fails for reasons unrelated to the code.
 *
 * The page renders fine without the webfont - the CSS declares a fallback stack,
 * and every layout keeps working.
 */
export const test = base.extend<{ blockExternal: void }>({
    blockExternal: [
        async ({ page }, use) => {
            await page.route('**/*', (route) => {
                const url = new URL(route.request().url());

                // Allow anything the app itself serves, and data:/blob: which
                // Livewire and Vite use internally. `[::1]` is included because
                // PHP's dev server and a dev Vite server both bind IPv6
                // loopback by default.
                const isLocal =
                    url.hostname === '127.0.0.1' ||
                    url.hostname === 'localhost' ||
                    url.hostname === '[::1]' ||
                    url.protocol === 'data:' ||
                    url.protocol === 'blob:';

                if (isLocal) {
                    return route.continue();
                }

                return route.abort();
            });

            await use();
        },
        { auto: true },
    ],
});

export { expect };
