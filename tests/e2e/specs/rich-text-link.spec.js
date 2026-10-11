// End-to-end: the link dialog every rich-text editor shares — « Texte du
// lien » above the address, and the « baguette magique » beside it that
// proposes the text (issue #840).
//
// WHY THIS SCENARIO EXISTS
// ----------------------------------------------------------------------------
// Vitest proves the dialog's answers turn into the right link
// (tests/js/rich-text-link.test.js), PHPUnit proves the endpoint's answers
// (tests/Core/Http/Controller/LinkLabelControllerTest.php). What neither
// can see is the whole gesture in a real browser: the dialog opening ON TOP
// of the editor's own Bootstrap modal — whose focus trap must not steal the
// fields — the selection surviving the round trip, the button reaching the
// real route with its CSRF token, and the link landing where the selection
// was.
//
// The address is a PDF on a host that does not resolve: the server cannot
// read it — the CI has no outbound network, and the scraper refuses this
// machine's own addresses anyway (SECURITY.md §17) — so the button falls
// back on the file's own name, which is exactly what it is for. No network
// is needed for that answer, and none is made.
//
// SELECTORS
// ----------------------------------------------------------------------------
// The dialog's fields and buttons are found by their labels and accessible
// names. The editable block and `#richTextEditorContent` are reached
// structurally, as in rich-text-paste.spec.js, for the reasons given there.
//
// ORDERING
// ----------------------------------------------------------------------------
// Nothing is saved: the editor is closed without « Enregistrer », and
// configuration mode is turned off again.
import { expect, test } from '@playwright/test';

import { answerCookieBanner } from '../support/cookie-banner.js';
import { loginAsAdmin } from '../support/admin-login.js';
import { openModal } from '../support/modal.js';

const PAGE = '/contact';
const PDF = 'https://scoutmagic-e2e.invalid/docs/Programme%20du%20camp.pdf';

/**
 * @param {import('@playwright/test').Page} page
 * @param {boolean} active
 */
async function setConfigurationMode(page, active) {
    await page.goto('/config/general', { waitUntil: 'domcontentloaded' });

    const card = page.locator('#main-content');
    const toTurnItOn = card.getByRole('button', { name: 'Activer', exact: true });
    const toTurnItOff = card.getByRole('button', { name: 'Désactiver', exact: true });
    const wanted = active ? toTurnItOn : toTurnItOff;

    if (await wanted.count() > 0) {
        await wanted.click();
    }

    await expect(active ? toTurnItOff : toTurnItOn).toBeVisible();
}

test('a link takes its text from the dialog, and the wand proposes it', async ({ page }) => {
    /** @type {string[]} */
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await loginAsAdmin(page);
    await answerCookieBanner(page);
    await setConfigurationMode(page, true);

    try {
        await page.goto(PAGE, { waitUntil: 'load' });
        const block = page.locator('.editable-content[data-key="contact.text"]');
        await block.hover();
        const editor = await openModal(page, 'richTextEditorModal', () =>
            block.locator('.editable-edit-btn').click());
        const surface = editor.locator('#richTextEditorContent');

        // One word selected in a known paragraph.
        await surface.evaluate((element) => {
            element.innerHTML = '<p>Voir le programme ici</p>';
            element.focus();
            const text = element.querySelector('p').firstChild;
            const range = document.createRange();
            range.setStart(text, 'Voir le '.length);
            range.setEnd(text, 'Voir le programme'.length);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        });

        // The site's one dialog (confirm.js), opened on top of the editor's
        // own modal. Answering it hands its id back at once, so it is not
        // closed through closeModal(): what is waited for is the link.
        const dialog = await openModal(page, 'sm-confirm-modal', () =>
            editor.getByRole('button', { name: 'Lien', exact: true }).click());
        await expect(dialog.getByRole('heading', { name: 'Insérer un lien' })).toBeVisible();

        const text = dialog.getByLabel('Texte du lien');
        const address = dialog.getByLabel('Adresse du lien');
        // The selected word is where the text starts.
        await expect(text).toHaveValue('programme');
        await expect(text).toBeFocused();

        // The wand without an address says what it needs, and asks nothing.
        const wand = dialog.getByRole('button', { name: 'Proposer le titre de la page' });
        await wand.click();
        await expect(dialog.getByText('Indiquez d\'abord l\'adresse du lien.')).toBeVisible();

        // With one, it proposes — here, the file's own name.
        await address.fill(PDF);
        const answered = page.waitForResponse((response) =>
            response.url().endsWith('/api/lien/libelle') && response.request().method() === 'POST');
        await wand.click();
        expect((await answered).status()).toBe(200);
        await expect(text).toHaveValue('Programme du camp.pdf');

        await dialog.getByRole('button', { name: 'Insérer le lien' }).click();

        // The link is where the word was, with the proposed text.
        const link = surface.locator('a');
        await expect(link).toHaveCount(1);
        await expect(link).toHaveAttribute('href', PDF);
        await expect(link).toHaveText('Programme du camp.pdf');
        // Chromium's own insertHTML may turn the spaces either side of what
        // it inserts into non-breaking ones; what is checked is the words.
        const paragraph = (await surface.locator('p').textContent())?.replace(/\u00a0/g, ' ');
        expect(paragraph).toBe('Voir le Programme du camp.pdf ici');
    } finally {
        await setConfigurationMode(page, false).catch(() => null);
    }

    expect(pageErrors).toEqual([]);
});
