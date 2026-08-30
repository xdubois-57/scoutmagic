// The one definition of "how much slower than a plain `npm run e2e` is
// this run expected to be".
//
// scripts/dast.sh sets E2E_TIMEOUT_FACTOR (DAST_TIMEOUT_FACTOR, 4 by
// default) because a security scan drives every request through OWASP ZAP
// and a TLS terminator, served by a single-worker PHP built-in server: the
// same scenarios do the same work, they just take several times longer to
// do it. playwright.config.js scales every ceiling it sets by this factor,
// which is the honest response — leaving them would report the harness's
// own latency as application failures.
//
// It lives here, rather than as a private function inside the config,
// because a SPEC needs it too. A hard-coded `{ timeout: 15_000 }` in a
// spec does not merely fail to scale: under the scan it becomes SMALLER
// than the ceiling the config would have given the same assertion by
// default (expect.timeout is scaled(10_000) — 40 s at factor 4), so
// writing the number by hand silently tightens the deadline in exactly
// the run that needs it loosest. That is what made
// specs/groups-mentions.spec.js's pin assertion flaky.
//
// Reach for it whenever a spec needs a ceiling of its own. A bare number
// is only ever right for a wait that is genuinely independent of how fast
// the server answers, and there are hardly any of those.

/**
 * @param {number} milliseconds the value a plain `npm run e2e` should use
 * @returns {number} that value, multiplied by E2E_TIMEOUT_FACTOR
 */
export function scaled(milliseconds) {
    const raw = Number(process.env.E2E_TIMEOUT_FACTOR);
    const factor = Number.isFinite(raw) && raw >= 1 ? raw : 1;

    return Math.round(milliseconds * factor);
}
