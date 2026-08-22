// Shared end-to-end helper: wait out Core\Security\HumanCheck's
// minimum-delay barrier before submitting a public form.
//
// Every form a NON-identified session can post — the magic-link request,
// a news article's response form, a rental request — carries a signed
// challenge, and HumanCheckService::verify() refuses a submission that
// arrives less than `human_check_min_delay_seconds` (3 by default) after
// the page rendered it. That is a real barrier, and this helper satisfies
// it rather than bypassing it: nothing here disables the check, lowers
// the setting, or forges a token. A real visitor clears it without
// noticing — reading the form and typing an answer takes far longer than
// three seconds — while an automated fill takes well under one.
//
// The wait is computed, not guessed: the challenge token is a base64 JSON
// object carrying the timestamp it was signed at (HumanCheckService::
// generateChallenge()), so the deadline is known exactly and a scenario
// never waits a second longer than the barrier actually needs.
import { expect } from '@playwright/test';

/** The setting's own default (Core\Security\HumanCheck\HumanCheckService). */
const DEFAULT_MIN_DELAY_SECONDS = 3;

/**
 * Wait until the human-check challenge inside `scope` is old enough to be
 * accepted.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} [scope] the form holding the
 *   challenge, when the page renders more than one (the login page renders
 *   two: the magic-link form's and the password-reset form's)
 */
export async function waitOutHumanCheckDelay(page, scope) {
    const field = (scope ?? page).locator('input[name="human_check_token"]').first();

    await expect(field).toHaveCount(1);

    const decoded = JSON.parse(
        Buffer.from(await field.inputValue(), 'base64').toString('utf8'),
    );
    const issuedAtMs = Number(decoded.t) * 1000;

    // The server compares whole seconds (time() - $timestamp >= min), so
    // being level with the boundary is already enough; the extra 300 ms
    // only covers the request being in flight when the second ticks over.
    const remaining = issuedAtMs + DEFAULT_MIN_DELAY_SECONDS * 1000 + 300 - Date.now();

    if (remaining > 0) {
        await page.waitForTimeout(remaining);
    }
}
