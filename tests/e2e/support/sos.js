// End-to-end support: waiting for the on-call admin page to answer taps.
//
// The counterpart of support/groups.js's waitForGroupsJsReady(), and it
// exists for the same reason, found the same way — a failure in CI that
// no reading of the spec could explain.
//
// public/assets/js/sos-admin.js binds the day list's click listener when
// it runs; the markup carries no handler of its own. So a rendered page
// is not yet a page that reacts, and a click landing before the file has
// run does NOTHING — no error, no effect, nothing in the console. The
// assertion that follows then waits out its full ceiling on an offcanvas
// that was never asked to open, and blames the offcanvas.
//
// This cost one failure per twenty runs of the DAST job while `npm run
// e2e` stayed green on the same commit: the security scan proxies every
// request through OWASP ZAP and a TLS terminator, which is enough extra
// latency to change which side of the race wins. The spec was not wrong
// about what it wanted; it was asking before anyone was listening.
//
// Wait for this after ANY navigation on /admin/sos — including the month
// links, which are ordinary <a href> full page loads, so the script has
// to run again each time.

/**
 * @param {import('@playwright/test').Page} page
 */
export async function waitForSosJsReady(page) {
    await page.waitForFunction(() => document.documentElement.classList.contains('sos-js'));
}
