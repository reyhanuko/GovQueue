import base from './playwright.config';

/**
 * TEMPORARY local override - delete after use.
 *
 * Two environment-specific concessions, neither of which belongs in the shared
 * config:
 *
 *   - reuseExistingServer. The committed config sets it false, which is right: it
 *     stops a browser run silently testing whatever the developer has running.
 *     Here the spawned server keeps dying between commands.
 *   - generous timeouts. This machine has under a gigabyte free, and a first
 *     request has to compile every view, so a 20s navigation budget is not
 *     reachable. The committed defaults are left alone.
 */
export default {
    ...base,
    // Seeded by hand instead. globalSetup's migrate:fresh --seed fails
    // intermittently here while the same command run directly succeeds, and
    // there is no flag to skip it. The committed config keeps it.
    globalSetup: undefined,
    webServer: undefined,
    timeout: 180_000,
    expect: { timeout: 30_000 },
    use: {
        ...base.use,
        actionTimeout: 60_000,
        navigationTimeout: 120_000,
    },
    projects: [
        {
            ...base.projects[0],
            use: { ...base.projects[0].use, actionTimeout: 60_000, navigationTimeout: 120_000 },
        },
    ],
};