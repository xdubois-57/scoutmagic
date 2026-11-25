// The real page must expose an uncertain household without blaming its
// members. Fixtures add no session, route or bypass; login remains real.
import { expect, test } from '@playwright/test';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loginAsAdmin } from '../support/admin-login.js';
import { answerCookieBanner } from '../support/cookie-banner.js';

const execFileAsync = promisify(execFile);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

async function fixture(action, stamp) {
    await execFileAsync('php', [path.join(root, 'scripts/e2e-support.php'), 'fees-household-fixture',
        action, process.env.E2E_INSTANCE_DIR, stamp], { timeout: 30_000 });
}

test('household uncertainty and IAmA are readable on mobile and desktop', async ({ page }, testInfo) => {
    const stamp = `fees${Date.now().toString(36)}${testInfo.repeatEachIndex}`;
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    page.on('response', response => {
        if (response.status() >= 500) failures.push(`HTTP ${response.status()}: ${response.url()}`);
    });
    try {
        await fixture('seed', stamp);
        await loginAsAdmin(page);
        await answerCookieBanner(page);
        await page.goto('/admin/fees/tarifs?vue=indeterminables', { waitUntil: 'load' });
        const card = page.locator('.card').filter({ has: page.getByRole('heading', { name: new RegExp(stamp) }) });
        await expect(card).toContainText('2 à 3 retenus pour le tarif du foyer');
        await expect(card).toContainText('Tarif IAmA saisi dans Desk');
        await expect(card).toContainText('Dates de début de fonction absentes ou incomplètes.');
        const rows = card.locator('tbody tr');
        await expect(rows).toHaveCount(3);
        await expect(rows.filter({ hasText: 'À corriger' })).toHaveCount(0);
        for (const width of [375, 1280]) {
            await page.setViewportSize({ width, height: 900 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            await expect(card.getByRole('button', { name: 'Ignorer ce foyer' })).toBeVisible();
            await testInfo.attach(`household-${width}`, { body: await card.screenshot(), contentType: 'image/png' });
        }
        expect(failures).toEqual([]);
    } finally {
        await fixture('clear', stamp);
    }
});
