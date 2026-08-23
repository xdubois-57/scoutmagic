// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and the site-wide dialogs
// (window.ScoutMagicConfirm / window.ScoutMagicToast, loaded by
// base.html.twig in production) are stubbed, so each confirmation's answer
// is something the test chooses rather than something a real modal has to
// be clicked for.
//
// Exercises the REAL public/assets/js/finance-categories.js (imported
// below, never reimplemented) on top of the real api.js envelope. The file
// is an IIFE that wires itself against the DOM present at import time, so
// every test builds its fixture first and imports afterwards — the
// tests/js/finance-receipts.test.js pattern.
//
// What this suite owns above all is the four confirmations this page asks
// before it changes anything, and the fact that a refusal reaches no
// endpoint at all: three of them destroy or overwrite data, and the
// confirm button has to name the action rather than say « OK »
// (design.md §7.5).
import { beforeEach, describe, expect, it, vi } from 'vitest';

function jsonResponse(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
}

async function settle() {
    // A macrotask hop drains the whole microtask queue first — the
    // ScoutMagicApi envelope plus the awaited confirmation add promise
    // layers a fixed number of Promise.resolve() awaits kept undercounting.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function buildDom() {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <button id="new-category-btn">Nouvelle catégorie</button>
        <button id="reset-default-categories-btn">Recréer les catégories par défaut</button>
        <button class="toggle-category-btn" data-id="4" data-active="1">Basculer</button>
        <button class="delete-category-btn" data-id="4">Supprimer</button>
        <button class="edit-category-btn" data-id="4" data-name="Camps" data-description="Frais de camp">Modifier</button>
        <button class="category-suggestion-btn" data-name="Cotisations">Cotisations</button>
        <div id="category-modal">
            <h2 id="category-modal-title"></h2>
            <div id="category-form-error" class="d-none"></div>
            <form id="category-form">
                <input id="category-id" value="">
                <input id="category-name" value="">
                <input id="category-description" value="">
            </form>
        </div>

        <button id="new-rule-btn">Nouvelle règle</button>
        <button id="reset-default-rules-btn">Réinitialiser les règles</button>
        <button id="run-rules-btn">Appliquer les règles</button>
        <span id="run-rules-status" class="d-none">En cours…</span>
        <div id="run-rules-result" class="d-none"></div>
        <div id="rule-modal">
            <h2 id="rule-modal-title"></h2>
            <div id="rule-form-error" class="d-none"></div>
            <form id="rule-form">
                <input id="rule-id" value="">
                <input id="rule-keyword-pattern" value="">
                <input id="rule-counterparty-account-pattern" value="">
                <input id="rule-amount-range" value="">
                <select id="rule-category"><option value="4">Camps</option></select>
            </form>
            <button id="test-rule-btn">Tester</button>
            <div id="test-rule-result"></div>
        </div>
        <button id="toggle-ai-rule-btn" data-active="0">IA</button>
        <div id="rules-list">
            <div class="rule-item" data-id="11">
                <button class="rule-move-up"></button>
                <button class="rule-move-down"></button>
                <button class="toggle-rule-btn" data-id="11" data-active="1"></button>
                <button class="edit-rule-btn" data-id="11" data-keyword-pattern="ALDI"
                        data-counterparty-account-pattern="" data-amount-range="" data-category-id="4"></button>
                <button class="delete-rule-btn" data-id="11"></button>
            </div>
            <div class="rule-item" data-id="12">
                <button class="rule-move-up"></button>
                <button class="rule-move-down"></button>
                <button class="delete-rule-btn" data-id="12"></button>
            </div>
        </div>
    `;
}

// The page fires one /config/finance/rules {action: run_status} on load, to
// reflect a background run started before the admin came back. Every test
// below is about what happens AFTER that, so boot() drains it and clears
// the call log — an assertion of "nothing was fetched" would otherwise be
// measuring the page's own opening move.
async function boot() {
    buildDom();
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/finance-categories.js');
    await settle();
    fetch.mockClear();
}

/**
 * Every request body posted to `url`, parsed.
 *
 * @param {string} url
 * @returns {any[]}
 */
function postsTo(url) {
    return fetch.mock.calls
        .filter((call) => call[0] === url)
        .map((call) => JSON.parse(call[1].body));
}

beforeEach(() => {
    vi.resetModules();
    vi.restoreAllMocks();
    global.fetch = vi.fn(() => jsonResponse({ success: true }));
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    window.ScoutMagicToast = { show: vi.fn() };
    delete window.bootstrap;
    Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
});

describe('finance-categories.js: deleting a category', () => {
    it('asks the shared confirmation — never the native box — with the French message and « Supprimer »', async () => {
        const nativeConfirm = vi.fn(() => true);
        window.confirm = nativeConfirm;
        await boot();

        document.querySelector('.delete-category-btn').dispatchEvent(new Event('click'));

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: 'Supprimer cette catégorie ?',
            confirmLabel: 'Supprimer',
        });
        expect(nativeConfirm).not.toHaveBeenCalled();
    });

    it('posts nothing when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();

        document.querySelector('.delete-category-btn').dispatchEvent(new Event('click'));
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('posts the delete and reloads when it resolves true', async () => {
        await boot();

        document.querySelector('.delete-category-btn').dispatchEvent(new Event('click'));
        await settle();

        expect(postsTo('/config/finance/categories')).toEqual([
            { action: 'delete', id: 4, _csrf_token: 'tok' },
        ]);
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('toasts the server error instead of reloading when the delete is refused', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Catégorie utilisée par un mouvement.' }));
        await boot();

        document.querySelector('.delete-category-btn').dispatchEvent(new Event('click'));
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
            'Catégorie utilisée par un mouvement.',
            { variant: 'error' },
        );
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});

describe('finance-categories.js: deleting a rule', () => {
    it('asks with the French message and « Supprimer »', async () => {
        await boot();

        document.querySelector('.delete-rule-btn').dispatchEvent(new Event('click'));

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: 'Supprimer cette règle ?',
            confirmLabel: 'Supprimer',
        });
    });

    it('posts nothing when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();

        document.querySelector('.delete-rule-btn').dispatchEvent(new Event('click'));
        await settle();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('posts the delete for the clicked rule when it resolves true', async () => {
        await boot();

        // The second row's button — the id has to come from the button, not
        // from the first rule in the list.
        document.querySelectorAll('.delete-rule-btn')[1].dispatchEvent(new Event('click'));
        await settle();

        expect(postsTo('/config/finance/rules')).toEqual([
            { action: 'delete', id: 12, _csrf_token: 'tok' },
        ]);
    });
});

describe('finance-categories.js: recreating the default categories', () => {
    it('asks with the full French sentence, « Recréer », and the primary variant', async () => {
        await boot();

        document.getElementById('reset-default-categories-btn').click();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: 'Recréer les catégories par défaut qui auraient été supprimées ? Les catégories existantes ne sont pas modifiées.',
            confirmLabel: 'Recréer',
            // Recreating a missing default touches nothing that exists.
            variant: 'primary',
        });
    });

    it('posts nothing when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();

        document.getElementById('reset-default-categories-btn').click();
        await settle();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('posts reset_defaults to the categories endpoint when it resolves true', async () => {
        await boot();

        document.getElementById('reset-default-categories-btn').click();
        await settle();

        expect(postsTo('/config/finance/categories')).toEqual([
            { action: 'reset_defaults', _csrf_token: 'tok' },
        ]);
        expect(window.location.reload).toHaveBeenCalled();
    });
});

describe('finance-categories.js: resetting the default rules', () => {
    it('asks with the full French sentence and « Réinitialiser », keeping the danger variant', async () => {
        await boot();

        document.getElementById('reset-default-rules-btn').click();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: 'Réinitialiser les règles par défaut ? Toute modification apportée aux règles marquées « Par défaut » sera perdue. Vos propres règles ne seront pas touchées.',
            // Edits to the default rules are lost: no `variant` key, so the
            // dialog's destructive default stands.
            confirmLabel: 'Réinitialiser',
        });
    });

    it('posts nothing when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();

        document.getElementById('reset-default-rules-btn').click();
        await settle();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('posts reset_defaults to the rules endpoint when it resolves true', async () => {
        await boot();

        document.getElementById('reset-default-rules-btn').click();
        await settle();

        expect(postsTo('/config/finance/rules')).toEqual([
            { action: 'reset_defaults', _csrf_token: 'tok' },
        ]);
    });

    it('toasts the server error rather than reloading when the reset is refused', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Règles verrouillées.' }));
        await boot();

        document.getElementById('reset-default-rules-btn').click();
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Règles verrouillées.', { variant: 'error' });
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});

describe('finance-categories.js: running the rules over uncategorised movements', () => {
    it('asks with the full French sentence, « Appliquer », and the primary variant', async () => {
        await boot();

        document.getElementById('run-rules-btn').click();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: 'Appliquer les règles de catégorisation à tous les mouvements non catégorisés ? Cette opération se déroule en arrière-plan.',
            confirmLabel: 'Appliquer',
            // Categorising adds information, it never removes any.
            variant: 'primary',
        });
    });

    it('starts nothing when the confirmation resolves false', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot();

        document.getElementById('run-rules-btn').click();
        await settle();

        expect(fetch).not.toHaveBeenCalled();
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('run-rules-btn')).disabled).toBe(false);
        expect(document.getElementById('run-rules-status').classList.contains('d-none')).toBe(true);
    });

    it('launches the background run and shows the running state when it resolves true', async () => {
        await boot();

        document.getElementById('run-rules-btn').click();
        await settle();

        expect(postsTo('/config/finance/rules')[0]).toEqual({
            action: 'run_on_uncategorized',
            _csrf_token: 'tok',
        });
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('run-rules-btn')).disabled).toBe(true);
        expect(document.getElementById('run-rules-status').classList.contains('d-none')).toBe(false);
    });

    it('toasts the refusal and stays idle when the run cannot be started', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Une exécution est déjà en cours.' }));
        await boot();

        document.getElementById('run-rules-btn').click();
        await settle();

        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
            'Une exécution est déjà en cours.',
            { variant: 'error' },
        );
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('run-rules-btn')).disabled).toBe(false);
    });
});

describe('finance-categories.js: rule reordering (no confirmation — a move is undoable)', () => {
    it('moving the second rule up re-orders the DOM and persists the new id order', async () => {
        await boot();

        document.querySelectorAll('.rule-move-up')[1].dispatchEvent(new Event('click'));
        await settle();

        const idsAfter = Array.from(document.querySelectorAll('#rules-list .rule-item')).map((r) => r.dataset.id);
        expect(idsAfter).toEqual(['12', '11']);
        expect(postsTo('/config/finance/rules')).toEqual([
            { action: 'reorder', ordered_ids: [12, 11], _csrf_token: 'tok' },
        ]);
        expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
    });

    it('moving the first rule up is a no-op — there is no previous rule', async () => {
        await boot();

        document.querySelectorAll('.rule-move-up')[0].dispatchEvent(new Event('click'));
        await settle();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('a move re-evaluates each button\'s own disabled state', async () => {
        await boot();

        document.querySelectorAll('.rule-move-down')[0].dispatchEvent(new Event('click'));
        await settle();

        const items = document.querySelectorAll('#rules-list .rule-item');
        expect(items[0].querySelector('.rule-move-up').disabled).toBe(true);
        expect(items[1].querySelector('.rule-move-down').disabled).toBe(true);
    });
});
