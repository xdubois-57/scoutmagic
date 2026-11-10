// End-to-end: « Sauvegardes récentes » on a 320 px screen (#847).
//
// The actions of a row — download, password, delete — used to be three
// siblings of a wrapping flex row, and the third went to the next line on
// a narrow phone. They are one group now, which never splits and never
// overflows; and a row whose archive left the disk offers only what still
// works: the password, kept for a copy already downloaded, and « Supprimer ».
// Only a real layout engine can say where a box lands, hence a browser.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { seedBackupRows } from '../support/backup-rows.js';

test('at 320 px a row keeps its actions on one line, and a row whose archive is gone offers only the key and the bin', async ({ page }) => {
    const ids = await seedBackupRows();
    // Logged in at the default size: the login helper looks for the logout
    // control, which a 320 px screen folds into the menu.
    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.setViewportSize({ width: 320, height: 640 });

    await page.goto('/config/maintenance/sauvegardes-recentes', { waitUntil: 'domcontentloaded' });

    const present = page.locator(`[data-backup-row="${ids.present}"]`);
    const missing = page.locator(`[data-backup-row="${ids.missing}"]`);
    await expect(present).toBeVisible();

    // One line: every action of the row shares the same top edge.
    const tops = await present.locator('.d-inline-flex.flex-nowrap > *').evaluateAll(
        (actions) => actions.map((action) => Math.round(action.getBoundingClientRect().top)),
    );
    expect(tops).toHaveLength(3);
    expect(new Set(tops).size).toBe(1);

    // No horizontal scroll on the page.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    // The row whose archive is gone: its badge, the key and the bin, no download.
    await expect(missing.getByText('Archive absente', { exact: true })).toBeVisible();
    await expect(missing.getByLabel(/^Télécharger la sauvegarde/)).toHaveCount(0);
    await expect(missing.getByLabel(/^Afficher le mot de passe de la sauvegarde/)).toBeVisible();
    await expect(missing.getByLabel(/^Supprimer la sauvegarde/)).toBeVisible();
    await expect(missing.getByText('Sur ce serveur')).toBeVisible();
});
