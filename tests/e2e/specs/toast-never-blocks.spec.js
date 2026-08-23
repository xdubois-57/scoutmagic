// End-to-end: a toast announces, and never intercepts a click meant for
// the page underneath it.
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// public/assets/js/toast.js parks every message in a
// `.toast-container.position-fixed.bottom-0.end-0` — the bottom-right
// corner, which is where a modal's footer buttons and most primary
// actions live. While a toast was up (4s for a success, 8s for an error)
// that corner was dead to clicks: press « Envoyer un test », then press
// « Lancer l'envoi », and nothing happens.
//
// It was CI that found it, on mass-mail-merge.spec.js, with the browser
// naming the culprit outright: « <div class="toast-body"> … subtree
// intercepts pointer events ». It had been passing locally because a
// faster machine let the toast expire before the next click — the kind of
// defect that hides behind timing until a slower runner exposes it.
//
// The check below measures what a click actually hits rather than
// asserting a CSS declaration: elementFromPoint at the toast's own centre
// must reach the page, and the toast's close button must still be
// reachable at its own.
import { test, expect } from '@playwright/test';

test('a toast lets a click through, but keeps its own close button', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });

    // A real toast, through the shared API every page uses.
    await page.evaluate(() => window.ScoutMagicToast.show('Email de test envoyé.'));
    const toast = page.locator('.toast-container .toast');
    await expect(toast).toBeVisible();

    const hit = await page.evaluate(() => {
        const el = document.querySelector('.toast-container .toast');
        const box = el.getBoundingClientRect();
        const centre = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);

        const closeBox = el.querySelector('.btn-close').getBoundingClientRect();
        const onClose = document.elementFromPoint(
            closeBox.x + closeBox.width / 2,
            closeBox.y + closeBox.height / 2,
        );

        return {
            centreIsInsideToast: el.contains(centre),
            closeIsReachable: el.querySelector('.btn-close').contains(onClose)
                || el.querySelector('.btn-close') === onClose,
        };
    });

    expect(
        hit.centreIsInsideToast,
        'A click aimed at the page must reach the page, not stop at the toast.',
    ).toBe(false);

    expect(
        hit.closeIsReachable,
        'The visitor must still be able to dismiss the toast by hand.',
    ).toBe(true);

    // And dismissing really works, not merely receives the pointer.
    await toast.locator('.btn-close').click();
    await expect(toast).toHaveCount(0);
});
