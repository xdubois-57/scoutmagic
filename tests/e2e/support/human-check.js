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
// Neither half of the wait is guessed. The challenge token is a base64
// JSON object carrying the timestamp it was signed at
// (HumanCheckService::generateChallenge()), and the THRESHOLD comes from
// the instance under test — scripts/e2e.sh reads
// `human_check_min_delay_seconds` out of the database it provisioned and
// exports it. So the deadline is known exactly, and a scenario never
// waits a second longer than the barrier actually needs.
//
// The threshold matters as much as the timestamp, and that is the whole
// of issue #453: it is a SETTING, not a constant. A spec that copied its
// default turned red the day somebody raised it, with no regression
// behind the failure and nothing in the message to say so. Centralising
// the copy in this file would only have made all seven specs wrong at
// once instead of two.
import { expect } from '@playwright/test';

/**
 * What the instance enforces, or the shipped default when a caller runs a
 * spec outside `scripts/e2e.sh` and nothing told us.
 */
const MIN_DELAY_SECONDS = Number.parseInt(process.env.E2E_HUMAN_CHECK_MIN_DELAY ?? '', 10) || 3;

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
    const remaining = issuedAtMs + MIN_DELAY_SECONDS * 1000 + 300 - Date.now();

    if (remaining > 0) {
        await page.waitForTimeout(remaining);
    }
}
