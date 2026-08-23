// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and the site-wide dialogs
// (window.ScoutMagicConfirm / window.ScoutMagicToast, loaded by
// base.html.twig in production) are stubbed, so the answer to the reset
// confirmation is something the test chooses rather than something a real
// modal has to be clicked for.
//
// Exercises the REAL public/assets/js/rgpd-config.js (imported below, never
// reimplemented) on top of the real api.js envelope. The file is an IIFE
// that wires itself against the DOM present at import time, so every test
// builds its fixture first and imports afterwards — the
// tests/js/finance-receipts.js pattern.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const RESET_MESSAGE = 'Réinitialiser au contenu par défaut ?';

function jsonResponse(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
}

async function settle() {
    // A macrotask hop drains the whole microtask queue first — the
    // ScoutMagicApi envelope adds promise layers a fixed number of
    // Promise.resolve() awaits kept undercounting.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

/** @param {string} mode the radio checked on first paint */
function buildDom(mode = 'default') {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <label><input type="radio" name="mode" value="default" ${mode === 'default' ? 'checked' : ''}></label>
        <label><input type="radio" name="mode" value="custom" ${mode === 'custom' ? 'checked' : ''}></label>
        <label><input type="radio" name="mode" value="ai" ${mode === 'ai' ? 'checked' : ''}></label>
        <div id="ai-prompt-card"><textarea id="ai-prompt"></textarea></div>
        <button id="generate-btn">Générer</button>
        <span id="generate-status"></span>
        <button id="reset-btn">Réinitialiser</button>
        <button id="edit-content-btn">Modifier</button>
        <div class="rich-text-field-preview" data-key="rgpd.text"><p>Texte actuel.</p></div>
        <div id="richTextEditorModal">
            <div id="richTextEditorContent"></div>
            <div class="modal-footer"><button id="richTextEditorSave">Enregistrer</button></div>
        </div>
    `;
}

/** @param {string} [mode] */
async function boot(mode) {
    buildDom(mode);
    vi.resetModules();
    // The real fetch toolbox — rgpd-config.js posts through the shared
    // window.ScoutMagicApi envelope (base.html.twig guarantees the load
    // order in production).
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/rgpd-config.js');
}

/** Every URL fetch was called with, in order. */
function fetchedUrls() {
    return fetch.mock.calls.map((call) => call[0]);
}

/** The parsed JSON body of the fetch call at `index`. */
function bodyOf(index) {
    return JSON.parse(fetch.mock.calls[index][1].body);
}

beforeEach(() => {
    vi.resetModules();
    vi.restoreAllMocks();
    global.fetch = vi.fn(() => jsonResponse({ success: true, content: '<p>Texte par défaut.</p>' }));
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    window.ScoutMagicToast = { show: vi.fn() };
    delete window.bootstrap;
});

describe('rgpd-config.js: reset to the default text', () => {
    it('asks the shared confirmation — never the native box — with the French message and « Réinitialiser »', async () => {
        const nativeConfirm = vi.fn(() => true);
        window.confirm = nativeConfirm;
        await boot();

        document.getElementById('reset-btn').click();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: RESET_MESSAGE,
            // Whatever was written or generated is replaced: this one keeps
            // the danger variant, so no `variant` key is passed at all.
            confirmLabel: 'Réinitialiser',
        });
        expect(nativeConfirm).not.toHaveBeenCalled();
    });

    it('fetches nothing when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();

        document.getElementById('reset-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
        expect(document.querySelector('.rich-text-field-preview').innerHTML).toBe('<p>Texte actuel.</p>');
    });

    it('resets, repaints the preview and persists the new content when it resolves true', async () => {
        await boot('custom');

        document.getElementById('reset-btn').click();
        await settle();

        expect(fetchedUrls()).toEqual(['/config/rgpd/reset', '/config/rgpd/save']);
        expect(document.querySelector('.rich-text-field-preview').innerHTML).toBe('<p>Texte par défaut.</p>');
        // The reset content is saved back under the mode currently checked,
        // not under 'default' — resetting is not a mode change.
        expect(bodyOf(1)).toMatchObject({
            mode: 'custom',
            content: '<p>Texte par défaut.</p>',
        });
    });

    it('leaves the preview alone when the server refuses the reset', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Refusé.' }));
        await boot();

        document.getElementById('reset-btn').click();
        await settle();

        expect(fetchedUrls()).toEqual(['/config/rgpd/reset']); // no save follows
        expect(document.querySelector('.rich-text-field-preview').innerHTML).toBe('<p>Texte actuel.</p>');
    });
});

describe('rgpd-config.js: which controls each generation mode shows', () => {
    it('hides the AI prompt card and the edit button in default mode', async () => {
        await boot('default');
        expect(document.getElementById('ai-prompt-card').style.display).toBe('none');
        expect(document.getElementById('edit-content-btn').style.display).toBe('none');
    });

    it('shows the edit button but not the prompt card in custom mode', async () => {
        await boot('custom');
        expect(document.getElementById('ai-prompt-card').style.display).toBe('none');
        expect(document.getElementById('edit-content-btn').style.display).toBe('inline-block');
    });

    it('shows both in AI mode', async () => {
        await boot('ai');
        expect(document.getElementById('ai-prompt-card').style.display).toBe('block');
        expect(document.getElementById('edit-content-btn').style.display).toBe('inline-block');
    });

    it('switching to custom keeps the current text and just records the mode', async () => {
        await boot('default');
        const custom = /** @type {HTMLInputElement} */ (document.querySelector('input[value="custom"]'));
        custom.checked = true;
        custom.dispatchEvent(new Event('change'));
        await settle();

        expect(fetchedUrls()).toEqual(['/config/rgpd/save']);
        expect(bodyOf(0)).toMatchObject({ mode: 'custom', content: '<p>Texte actuel.</p>' });
    });

    it('switching to AI with an empty prompt resets but never generates', async () => {
        await boot('default');
        const ai = /** @type {HTMLInputElement} */ (document.querySelector('input[value="ai"]'));
        ai.checked = true;
        ai.dispatchEvent(new Event('change'));
        await settle();

        expect(fetchedUrls()).toEqual(['/config/rgpd/reset']);
    });

    it('switching to AI with a prompt resets, then generates from that prompt', async () => {
        await boot('default');
        /** @type {HTMLTextAreaElement} */ (document.getElementById('ai-prompt')).value = '  Association scoute  ';
        const ai = /** @type {HTMLInputElement} */ (document.querySelector('input[value="ai"]'));
        ai.checked = true;
        ai.dispatchEvent(new Event('change'));
        await settle();

        expect(fetchedUrls()).toEqual(['/config/rgpd/reset', '/config/rgpd/generate']);
        expect(bodyOf(1)).toMatchObject({ prompt: 'Association scoute' }); // trimmed
    });
});

describe('rgpd-config.js: the generate button', () => {
    it('re-enables itself and reports the server error line on a refusal', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Aucun connecteur IA actif.' }));
        await boot('ai');
        const btn = /** @type {HTMLButtonElement} */ (document.getElementById('generate-btn'));

        btn.click();
        expect(btn.disabled).toBe(true);
        await settle();

        expect(btn.disabled).toBe(false);
        expect(document.getElementById('generate-status').textContent).toContain('Aucun connecteur IA actif.');
    });

    it('reports an invalid server response rather than an empty error line', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot('ai');

        document.getElementById('generate-btn').click();
        await settle();

        expect(document.getElementById('generate-status').textContent)
            .toContain('Erreur : réponse serveur invalide.');
    });

    it('paints the generated content into the preview on success', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true, content: '<p>Texte généré.</p>' }));
        await boot('ai');

        document.getElementById('generate-btn').click();
        await settle();

        expect(document.querySelector('.rich-text-field-preview').innerHTML).toBe('<p>Texte généré.</p>');
        expect(document.getElementById('generate-status').textContent).toContain('Contenu généré et enregistré');
    });
});
