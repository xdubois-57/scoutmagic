// The browser/server seam: five submitted tariffs, a live annual rule and
// explicit application of a partial proposal to CURRENT fields. A CLI fixture
// supplies only the proposal, after real login; it never authenticates anyone
// or calls an AI. HTTP fetching and AI parsing are covered by PHP tests.
import { expect, test } from '@playwright/test';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loginAsAdmin } from '../support/admin-login.js';
import { answerCookieBanner } from '../support/cookie-banner.js';

const execFileAsync = promisify(execFile);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

async function offerProposal(page, year) {
    const session = (await page.context().cookies()).find(cookie => cookie.name === 'SM_SESSION');
    expect(session, 'the fixture may only add a proposal to an existing authenticated session').toBeDefined();
    await execFileAsync('php', [path.join(root, 'scripts/e2e-support.php'),
        'seed-fees-scale-proposal', session.value, year], { timeout: 30_000 });
    await page.reload({ waitUntil: 'load' });
}

async function openScale(page) {
    const toggle = page.getByRole('button', { name: 'Barème des cotisations' });
    if (await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
    await expect(page.getByRole('button', { name: 'Enregistrer le barème' })).toBeVisible();
}

test('the scale keeps edits, applies a partial proposal explicitly and saves all five tariffs', async ({ page }, testInfo) => {
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    page.on('response', response => {
        if (response.status() >= 500) failures.push(`HTTP ${response.status()}: ${response.url()}`);
    });
    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await page.goto('/admin/fees/tarifs', { waitUntil: 'load' });
    await openScale(page);
    const form = page.locator('#fees-scale-form');
    const year = await form.locator('[name="scale_year"]').inputValue();
    const amount = label => page.getByLabel(`Montant par personne (€) — ${label}`, { exact: true });
    for (const [label, value] of [['Normale', '61,11'], ['Couple', '0'], ['Familiale', ''],
        ['IAmA liés à l’unité', '25'], ['Invités', '18,25']]) {
        await amount(label).fill(value);
    }
    await page.getByLabel('Du (jour/mois)', { exact: true }).fill('01/06');
    await page.getByLabel('Au (jour/mois), inclus', { exact: true }).fill('15/09');
    await expect(page.locator('#fees-guest-rule')).toContainText('18,25 €');
    await expect(page.locator('#fees-guest-rule')).toContainText(`année civile ${year.slice(5)}`);
    await expect(page.locator('#fees-guest-rule')).toContainText('15/09 inclus');

    // Show that merely seeing the proposal cannot replace a stored price.
    await page.getByRole('button', { name: 'Enregistrer le barème' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Barème enregistré.' })).toBeVisible();
    await offerProposal(page, year);
    await expect(amount('Normale')).toHaveValue('61,11');
    await expect(page.getByRole('heading', { name: `Proposition fédérale pour ${year}` })).toBeVisible();
    await expect(page.getByText('À compléter : Couple, Familiale.')).toBeVisible();

    for (const width of [375, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await expect(amount('Invités')).toBeVisible();
        await testInfo.attach(`scale-${width}`, { body: await form.screenshot(), contentType: 'image/png' });
    }
    await page.setViewportSize({ width: 375, height: 900 });
    await amount('Couple').fill('47,23');
    await page.getByRole('button', { name: 'Reprendre les montants', exact: true }).click();
    await expect(amount('Normale')).toHaveValue('58,12');
    await expect(amount('Couple')).toHaveValue('47,23');
    await expect(amount('Familiale')).toHaveValue('');
    await expect(page.getByLabel('Au (jour/mois), inclus', { exact: true })).toHaveValue('15/09');
    // Reload proves the unsaved draft and date choice survive the redirect.
    await page.reload({ waitUntil: 'load' });
    await expect(amount('Couple')).toHaveValue('47,23');
    await offerProposal(page, year);
    await page.getByRole('button', { name: 'Reprendre les montants et la période', exact: true }).click();
    await expect(page.getByLabel('Au (jour/mois), inclus', { exact: true })).toHaveValue('14/09');
    await page.getByRole('button', { name: 'Enregistrer le barème' }).click();
    await page.reload({ waitUntil: 'load' });
    await openScale(page);
    await expect(amount('Normale')).toHaveValue('58,12');
    await expect(amount('Couple')).toHaveValue('47,23');
    await expect(amount('Familiale')).toHaveValue('');
    await expect(amount('IAmA liés à l’unité')).toHaveValue('26,13');
    await expect(amount('Invités')).toHaveValue('19,14');
    await expect(page.getByLabel('Au (jour/mois), inclus', { exact: true })).toHaveValue('14/09');
    await expect(page.getByRole('heading', { name: `Proposition fédérale pour ${year}` })).toHaveCount(0);
    expect(failures).toEqual([]);
});
