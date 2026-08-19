// Playwright configuration for ScoutMagic's end-to-end tests.
//
// Test infrastructure only — the same "development/test tooling, never a
// frontend build step" footing as vitest.config.js (see AGENTS.md § CSS /
// frontend and ARCHITECTURE.md § 15). Production ScoutMagic still ships
// plain, unbundled browser JavaScript and needs neither Node nor a
// browser runtime to run or deploy.
//
// Deliberately narrow for this first iteration:
//   - Chromium only. The goal is a stable boot/release gate, not
//     cross-engine compatibility coverage; adding WebKit/Firefox triples
//     the browser download and the failure surface for no extra signal
//     about "does ScoutMagic boot and render".
//   - Headless, always. There is no graphical session in CI, and a
//     remote/constrained Claude Code environment does not have one either
//     (README.md § Tests end-to-end).
//   - One worker. A single PHP built-in server serves the whole run; it
//     handles one request at a time, so parallel workers would contend
//     for it rather than finish sooner.
//   - No retries anywhere, CI included. A retry budget on a two-assertion
//     boot test hides exactly the flakiness this gate exists to surface.
//
// The server is NOT started by Playwright's own `webServer` option: the
// full lifecycle (throwaway install, database, free port, readiness
// polling, teardown) lives in scripts/e2e.sh so that the release script,
// CI, and a developer all drive the identical one-command path. This
// config expects E2E_BASE_URL to already point at a running instance,
// and fails loudly rather than silently testing something else.
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL;

if (!baseURL) {
    throw new Error(
        'E2E_BASE_URL is not set. Run the end-to-end tests via `npm run e2e` '
        + '(scripts/e2e.sh), which provisions the application instance and sets it.',
    );
}

export default defineConfig({
    testDir: './specs',
    outputDir: './test-results',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    // A generous but finite ceiling: a cold first request pays for Twig
    // template compilation and the module registry scan, and CI runners
    // are slower than a laptop. Nothing here should ever wait this long.
    timeout: 60_000,
    expect: { timeout: 10_000 },
    // Fails the run if a test is left focused with .only, which would
    // silently reduce a release gate to a subset of itself.
    forbidOnly: !!process.env.CI,
    // 'list' for whoever is watching the run (a developer's terminal, the
    // CI log, scripts/release.sh's gate output), plus an HTML report that
    // CI uploads as an artifact when the job fails.
    reporter: [['list'], ['html', { outputFolder: './playwright-report', open: 'never' }]],
    use: {
        baseURL,
        // Diagnostics on failure only — a green run leaves nothing behind
        // to upload or clean up.
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        // The service worker (public/sw.js) would keep fetching and
        // caching pages in the background for the whole run, making both
        // the request log this test asserts on and the server's load
        // non-deterministic. Registering it is its own feature with its
        // own future scenario; it is not part of "does the application
        // boot and render".
        serviceWorkers: 'block',
        actionTimeout: 10_000,
        navigationTimeout: 30_000,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
