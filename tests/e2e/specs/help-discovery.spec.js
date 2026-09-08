// End-to-end: « Le saviez-vous ? », the discovery dialog (ARCHITECTURE.md
// §8.95).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Every other layer tests one half of this feature and cannot reach the
// other. The PHP tests decide which topics are eligible and what the
// endpoint writes; the Vitest spec walks the cards in a jsdom with a fake
// fetch. Neither ever puts the two together, and the join is where this
// feature actually lives: a server that decides there is a dialog, a
// template that renders it, a script the CSP has to allow, a Bootstrap
// modal that really opens, and ONE call back that has to reach a route
// wired in the composition root with a CSRF token the page carries. Any
// one of those can be wrong while both suites stay green.
//
// It is also the one place the *closing* is provable end to end: the tips
// come back on every page load until the dialog is closed once, so
// « the second page has no dialog » is the assertion that says the write
// happened, travelled and was read back.
//
// THE FIXTURE HAS THE FEATURE OFF, AND THIS SPEC TURNS IT ON FOR ITSELF.
// scripts/e2e-support.php sets `help_discovery_enabled` to 0 when it
// provisions the instance: a modal's backdrop intercepts pointer events,
// and with it on, forty-four scenarios that have nothing to do with the
// help could no longer click anything at all. This one switches it back
// through the application's own settings endpoint — the same call the
// Réglages page makes — so nothing here bypasses the site to set up.
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../support/admin-login.js';
import { answerCookieBanner } from '../support/cookie-banner.js';
import { answerConfirmation, waitForConfirmReady } from '../support/confirm-dialog.js';

const DIALOG = '#help-discovery-modal';

/**
 * Turns the tips on (or off) through `POST /config/settings/update`, the
 * endpoint the Réglages page itself posts to — CSRF token included, read
 * from the page the browser is already on.
 *
 * @param {import('@playwright/test').Page} page
 * @param {boolean} enabled
 */
async function setDiscoveryEnabled(page, enabled) {
    const answer = await page.evaluate(async (value) => {
        const token = document.querySelector('meta[name="csrf-token"]');
        const response = await fetch('/config/settings/update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                key: 'help_discovery_enabled',
                value,
                _csrf_token: token instanceof HTMLMetaElement ? token.content : '',
            }),
        });
        return { status: response.status, body: await response.json() };
    }, enabled ? '1' : '0');

    expect(answer.status, 'the settings endpoint answered').toBe(200);
    expect(answer.body.success, 'the setting was accepted').toBe(true);
}

test('a tip is offered, walked and closed — and then leaves the reader alone', async ({ page }) => {
    await loginAsAdmin(page);

    // Deliberately BEFORE answering the cookie banner: the setting is
    // turned on through a fetch, which needs no click, so the next
    // navigation is the state every account really meets on its first
    // page after signing in — tips armed, banner still up.
    await page.goto('/config/settings', { waitUntil: 'domcontentloaded' });
    await setDiscoveryEnabled(page, true);

    // ---- Nothing is offered over the consent banner -------------------
    // The banner is at z-index 1035 and a modal backdrop at 1050, so a
    // dialog here would cover the decision the site is asking for AND
    // swallow every click aimed at it — and dismissing it to get through
    // is a close, which consumes the batch and snoozes the account for a
    // day. Asserted before the click below, because otherwise this
    // regression surfaces as an unexplained timeout on « Tout accepter ».
    await page.goto('/', { waitUntil: 'load' });
    await expect(
        page.locator(DIALOG),
        'no tip may stack on top of the cookie banner',
    ).toHaveCount(0);

    await answerCookieBanner(page, { accept: true });

    // ---- The dialog opens on an ordinary page -------------------------
    await page.goto('/', { waitUntil: 'load' });

    const dialog = page.locator(DIALOG);
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText('Le saviez-vous ?')).toBeVisible();

    const position = dialog.locator('[data-discovery-position]');
    const cards = dialog.locator('[data-discovery-card]');
    const total = await cards.count();
    expect(total, 'the batch is not empty and is bounded by help_discovery_batch_size')
        .toBeGreaterThan(1);
    await expect(position).toHaveText(`1 / ${total}`);

    // One card at a time — the walk is the whole component.
    await expect(cards.nth(0)).toBeVisible();
    await expect(cards.nth(1)).toBeHidden();

    const firstId = await cards.nth(0).getAttribute('data-discovery-id');
    const secondId = await cards.nth(1).getAttribute('data-discovery-id');
    expect(firstId, 'a card names the topic it shows').toBeTruthy();
    expect(secondId).not.toBe(firstId);

    await dialog.getByRole('button', { name: 'Suivant' }).click();
    await expect(position).toHaveText(`2 / ${total}`);
    await expect(cards.nth(0)).toBeHidden();
    await expect(cards.nth(1)).toBeVisible();

    // ---- Closing writes, once ----------------------------------------
    // Walk to the last card, where « Suivant » becomes « Terminé ».
    for (let step = 2; step < total; step++) {
        await dialog.getByRole('button', { name: 'Suivant' }).click();
    }
    await expect(position).toHaveText(`${total} / ${total}`);

    const recorded = page.waitForResponse(
        (response) => response.url().includes('/api/aide/decouverte') && response.request().method() === 'POST',
    );
    await dialog.getByRole('button', { name: 'Terminé' }).click();
    const written = await recorded;
    expect(written.status(), 'the close reached the endpoint').toBe(200);
    expect(JSON.parse(written.request().postData() || '{}').action).toBe('close');

    await expect(dialog).toBeHidden();

    // ---- And it stays closed ------------------------------------------
    // The delay is `help_discovery_interval_hours` (24 by default), so the
    // very next page must carry no dialog at all — not a hidden one. That
    // is a server decision: the global is simply not set.
    await page.goto('/notifications', { waitUntil: 'load' });
    await expect(page.locator(DIALOG)).toHaveCount(0);

    // ---- Never on the help itself -------------------------------------
    await page.goto('/aide', { waitUntil: 'load' });
    await expect(page.locator(DIALOG)).toHaveCount(0);

    // ---- « Revoir les astuces » puts it all back ----------------------
    await page.goto('/account', { waitUntil: 'load' });
    await waitForConfirmReady(page);

    const revoir = page.getByRole('button', { name: 'Revoir les astuces' });
    await expect(revoir, 'offered because this account has now seen some').toBeVisible();
    await revoir.click();
    await answerConfirmation(page);

    await expect(page.getByText('Les astuces vous seront proposées à nouveau.')).toBeVisible();
    await expect(page.locator(DIALOG), 'the reset clears the delay too').toBeVisible();

    // Leave the instance as the fixture provisioned it: every other spec
    // in this run shares it, and a dialog left armed intercepts their
    // clicks exactly as it did before it was switched off.
    await page.locator(DIALOG).getByRole('button', { name: 'Ne plus me proposer' }).click();
    await expect(page.locator(DIALOG)).toBeHidden();
    await page.goto('/config/settings', { waitUntil: 'domcontentloaded' });
    await setDiscoveryEnabled(page, false);
});
