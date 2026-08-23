// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked throughout. Exercises the REAL
// implementation in public/assets/js/finance-accounts.js (imported below,
// never reimplemented here) — the finance accounts configuration page's
// create/edit dialog and its activate/deactivate toggle.
//
// Two things this suite exists to hold in place, beyond "the dialog fills
// in": the request always carries the CSRF token (the page posts to
// /config/finance/accounts, which refuses a request without one), and HTTP
// success is never read as business success — a 500 that is not JSON at all
// must surface as an error, not as a saved account (api.js § envelope).
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** One JSON answer as ScoutMagicApi's envelope reads it. */
function jsonResponse(data, { ok = true, status = 200 } = {}) {
    return Promise.resolve({ ok, status, json: () => Promise.resolve(data) });
}

/** An HTML error page — what a 500 actually returns. res.json() rejects. */
function htmlErrorResponse(status = 500) {
    return Promise.resolve({
        ok: false,
        status,
        json: () => Promise.reject(new SyntaxError('Unexpected token < in JSON')),
    });
}

async function settle() {
    // A macrotask hop drains the whole microtask queue first — the
    // ScoutMagicApi envelope adds promise layers a fixed number of
    // Promise.resolve() awaits kept undercounting. Requires real timers.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

// One bank account (active, not default), one cash account flagged
// « Par défaut » and currently inactive — enough for both availability
// rules and both toggle directions.
function buildDom() {
    document.body.innerHTML = `
        <button type="button" id="new-account-btn">Nouveau compte</button>
        <table class="table"><tbody>
            <tr>
                <td>Compte courant</td>
                <td class="text-end">
                    <button type="button" class="edit-account-btn"
                            data-id="7" data-name="Compte courant" data-account-type="bank"
                            data-section-id="3" data-role-min-view="chef"
                            data-iban="BE71 0961 2345 6769" data-holder="Unité Saint-Jean"
                            data-is-default="0" aria-label="Modifier"></button>
                    <button type="button" class="toggle-account-btn" data-id="7" data-active="1"
                            aria-label="Actif — cliquer pour désactiver"></button>
                </td>
            </tr>
            <tr>
                <td>Caisse</td>
                <td class="text-end">
                    <button type="button" class="edit-account-btn"
                            data-id="9" data-name="Caisse" data-account-type="cash"
                            data-section-id="" data-role-min-view="tresorier"
                            data-iban="" data-holder=""
                            data-is-default="1" aria-label="Modifier"></button>
                    <button type="button" class="toggle-account-btn" data-id="9" data-active="0"
                            aria-label="Inactif — cliquer pour activer"></button>
                </td>
            </tr>
        </tbody></table>
        <div class="modal" id="account-modal">
            <h5 id="account-modal-title">Nouveau compte</h5>
            <form id="account-form">
                <input type="hidden" id="account-id" value="">
                <input type="text" id="account-name" required>
                <select id="account-type">
                    <option value="bank">Compte bancaire</option>
                    <option value="cash">Caisse</option>
                </select>
                <select id="account-section">
                    <option value="">—</option>
                    <option value="3">Louveteaux</option>
                </select>
                <input type="text" id="account-iban">
                <input type="text" id="account-holder">
                <select id="account-role-min-view">
                    <option value="chef">Chef</option>
                    <option value="tresorier">Trésorier</option>
                </select>
                <div class="text-danger small mt-2 d-none" id="account-form-error" role="alert"></div>
            </form>
            <button type="button" data-bs-dismiss="modal">Annuler</button>
            <button type="submit" form="account-form">Enregistrer</button>
        </div>
    `;
}

async function boot() {
    buildDom();
    vi.resetModules();
    // The real toolboxes — finance-accounts.js fetches through
    // window.ScoutMagicApi and reports failures through
    // window.ScoutMagicToast (base.html.twig guarantees this load order in
    // production). The confirmation dialog is stubbed: this page asks
    // nothing, and the assertions below prove no native box is used either.
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    await import('../../public/assets/js/finance-accounts.js');
}

const el = (id) => document.getElementById(id);
const editBtn = (id) => document.querySelector(`.edit-account-btn[data-id="${id}"]`);
const toggleBtn = (id) => document.querySelector(`.toggle-account-btn[data-id="${id}"]`);
const submitBtn = () => document.querySelector('button[type="submit"][form="account-form"]');

/** The one toast currently on screen, as text. */
const toastText = () => {
    const body = document.querySelector('.toast-body');
    return body ? body.textContent : null;
};

function submitForm() {
    el('account-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

beforeEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const meta = document.createElement('meta');
    meta.name = 'csrf-token';
    meta.content = 'tok';
    document.head.appendChild(meta);
    global.fetch = vi.fn();
    delete window.bootstrap;
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    window.alert = vi.fn();
    // Every success path reloads the page; jsdom has no navigation.
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { reload: vi.fn(), href: 'http://localhost/config/finance/accounts' },
    });
});

describe('finance-accounts.js: boot guard', () => {
    it('does nothing on a page without the account dialog', async () => {
        // The handlers used to dereference document.getElementById('account-type')
        // unconditionally, which throws on any page this file reaches
        // without the modal markup.
        document.body.innerHTML = '<button type="button" class="toggle-account-btn" data-id="7" data-active="1"></button>';
        vi.resetModules();
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/toast.js');
        await expect(import('../../public/assets/js/finance-accounts.js')).resolves.toBeDefined();

        expect(() => document.querySelector('.toggle-account-btn').click()).not.toThrow();
        await settle();
        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('finance-accounts.js: the account dialog', () => {
    it('opens an empty « Nouveau compte » dialog', async () => {
        await boot();
        el('account-name').value = 'Reste du dialogue précédent';
        el('account-id').value = '7';
        el('account-form-error').classList.remove('d-none');

        el('new-account-btn').click();

        expect(el('account-modal-title').textContent).toBe('Nouveau compte');
        expect(el('account-id').value).toBe('');
        expect(el('account-name').value).toBe('');
        expect(el('account-form-error').classList.contains('d-none')).toBe(true);
        expect(el('account-modal').classList.contains('show')).toBe(true);
        // A new account starts as a non-default one: its identity fields
        // are all editable.
        expect(el('account-name').disabled).toBe(false);
        expect(el('account-type').disabled).toBe(false);
        expect(el('account-section').disabled).toBe(false);
    });

    it('fills « Modifier le compte » from the row button\'s data attributes', async () => {
        await boot();

        editBtn(7).click();

        expect(el('account-modal-title').textContent).toBe('Modifier le compte');
        expect(el('account-id').value).toBe('7');
        expect(el('account-name').value).toBe('Compte courant');
        expect(el('account-type').value).toBe('bank');
        expect(el('account-section').value).toBe('3');
        expect(el('account-role-min-view').value).toBe('chef');
        expect(el('account-iban').value).toBe('BE71 0961 2345 6769');
        expect(el('account-holder').value).toBe('Unité Saint-Jean');
        expect(el('account-iban').disabled).toBe(false);
    });

    it('locks name, type and section on a « Par défaut » account, and frees them again on the next one', async () => {
        await boot();

        editBtn(9).click();
        expect(el('account-name').disabled).toBe(true);
        expect(el('account-type').disabled).toBe(true);
        expect(el('account-section').disabled).toBe(true);

        editBtn(7).click();
        expect(el('account-name').disabled).toBe(false);
        expect(el('account-type').disabled).toBe(false);
        expect(el('account-section').disabled).toBe(false);
    });

    it('disables and clears the bank fields for a cash account, both on edit and on a type change', async () => {
        await boot();

        // The default account of the fixture is a cash one, with no IBAN.
        editBtn(9).click();
        expect(el('account-iban').disabled).toBe(true);
        expect(el('account-holder').disabled).toBe(true);

        // Switching an existing bank account to « Caisse » empties the two
        // fields rather than posting an IBAN the account may not keep.
        editBtn(7).click();
        expect(el('account-iban').value).toBe('BE71 0961 2345 6769');
        el('account-type').value = 'cash';
        el('account-type').dispatchEvent(new Event('change', { bubbles: true }));

        expect(el('account-iban').value).toBe('');
        expect(el('account-holder').value).toBe('');
        expect(el('account-iban').disabled).toBe(true);
    });
});

describe('finance-accounts.js: saving an account', () => {
    it('POSTs a creation with every field and the CSRF token, then reloads', async () => {
        await boot();
        el('new-account-btn').click();
        el('account-name').value = 'Compte camp';
        el('account-type').value = 'bank';
        el('account-section').value = '3';
        el('account-iban').value = 'BE71 0961 2345 6769';
        el('account-holder').value = 'Unité Saint-Jean';
        el('account-role-min-view').value = 'tresorier';

        fetch.mockReturnValue(jsonResponse({ success: true }));
        submitForm();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/config/finance/accounts');
        expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'POST' });
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({
            action: 'create',
            name: 'Compte camp',
            account_type: 'bank',
            section_id: '3',
            iban: 'BE71 0961 2345 6769',
            holder_name: 'Unité Saint-Jean',
            role_min_view: 'tresorier',
            _csrf_token: 'tok',
        });
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('POSTs an update carrying the account id as a number', async () => {
        await boot();
        editBtn(7).click();
        el('account-name').value = 'Compte courant (2026)';

        fetch.mockReturnValue(jsonResponse({ success: true }));
        submitForm();
        await settle();

        const body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.action).toBe('update');
        expect(body.id).toBe(7);
        expect(body.name).toBe('Compte courant (2026)');
        expect(body._csrf_token).toBe('tok');
    });

    it('keeps the submit button disabled for the whole request', async () => {
        await boot();
        el('new-account-btn').click();
        el('account-name').value = 'Compte camp';

        let finish;
        fetch.mockReturnValueOnce(new Promise((resolve) => { finish = resolve; }));
        submitForm();
        await settle();

        expect(submitBtn().disabled).toBe(true);

        finish({ ok: true, status: 200, json: () => Promise.resolve({ success: true }) });
        await settle();

        // Re-enabled on every path — the half a hand-written try/finally
        // pair keeps forgetting.
        expect(submitBtn().disabled).toBe(false);
    });

    it('shows the server\'s refusal in the form error box instead of reloading', async () => {
        await boot();
        el('new-account-btn').click();

        fetch.mockReturnValue(jsonResponse({ success: false, error: 'Un compte porte déjà ce nom.' }));
        submitForm();
        await settle();

        expect(el('account-form-error').textContent).toBe('Un compte porte déjà ce nom.');
        expect(el('account-form-error').classList.contains('d-none')).toBe(false);
        expect(window.location.reload).not.toHaveBeenCalled();
        expect(submitBtn().disabled).toBe(false);
    });

    it('treats an HTTP 200 carrying success:false as a failure, not a success', async () => {
        // `ok` is HTTP-level, `data.success` is the business answer — the
        // two must never be conflated (api.js § envelope).
        await boot();
        el('new-account-btn').click();

        fetch.mockReturnValue(jsonResponse({ success: false }, { ok: true, status: 200 }));
        submitForm();
        await settle();

        expect(window.location.reload).not.toHaveBeenCalled();
        expect(el('account-form-error').textContent).toBe('Une erreur est survenue.');
    });

    it('reports a 500 that is not JSON instead of reading it as a saved account', async () => {
        await boot();
        el('new-account-btn').click();

        fetch.mockReturnValue(htmlErrorResponse(500));
        submitForm();
        await settle();

        expect(el('account-form-error').textContent).toBe('Erreur : réponse serveur invalide.');
        expect(el('account-form-error').classList.contains('d-none')).toBe(false);
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});

describe('finance-accounts.js: the activate/deactivate toggle', () => {
    it('deactivates an active account', async () => {
        await boot();
        fetch.mockReturnValue(jsonResponse({ success: true }));

        toggleBtn(7).click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/config/finance/accounts');
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({
            action: 'deactivate',
            id: 7,
            _csrf_token: 'tok',
        });
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('activates an inactive account', async () => {
        await boot();
        fetch.mockReturnValue(jsonResponse({ success: true }));

        toggleBtn(9).click();
        await settle();

        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({
            action: 'activate',
            id: 9,
            _csrf_token: 'tok',
        });
    });

    it('shows a refusal as an error toast — never the native alert this site replaced', async () => {
        await boot();
        fetch.mockReturnValue(jsonResponse({ success: false, error: 'Un IBAN et un titulaire sont requis.' }));

        toggleBtn(9).click();
        await settle();

        expect(toastText()).toBe('Un IBAN et un titulaire sont requis.');
        expect(document.querySelector('.toast').className).toContain('text-bg-danger');
        // design.md §7.5 — the browser's own box is never used again.
        expect(window.alert).not.toHaveBeenCalled();
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('toasts a 500 that is not JSON rather than reloading as if it had worked', async () => {
        await boot();
        fetch.mockReturnValue(htmlErrorResponse(500));

        toggleBtn(7).click();
        await settle();

        expect(toastText()).toBe('Erreur : réponse serveur invalide.');
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('toasts a network failure the same way', async () => {
        await boot();
        fetch.mockReturnValue(Promise.reject(new TypeError('Failed to fetch')));

        toggleBtn(7).click();
        await settle();

        expect(toastText()).toBe('Erreur : réponse serveur invalide.');
        expect(window.location.reload).not.toHaveBeenCalled();
    });
});
