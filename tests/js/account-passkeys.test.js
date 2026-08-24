// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, so are the two
// site-wide dialog toolboxes (window.ScoutMagicConfirm,
// window.ScoutMagicToast), window.location.reload (jsdom does not
// implement navigation) and navigator.credentials (jsdom has no
// WebAuthn). Exercises the REAL implementation in
// public/assets/js/account-passkeys.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the
// module via vi.resetModules() + await import().
//
// The fixture mirrors what core/View/templates/account/index.html.twig
// renders: one registered key with its delete button, the error box the
// refused-deletion path fills, the (always present, hidden) empty state,
// and the add section with its deferred name field.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const KEY_ROW = `
    <div data-passkey-id="7">
        <span class="small">iPhone de Jean</span>
        <button type="button" class="passkey-delete-btn" data-id="7"></button>
    </div>`;

const PAGE = `
    <div id="passkey-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="passkey-list">
        ${KEY_ROW}
        <div id="no-passkeys-msg" class="d-none"><p>Aucune clé enregistrée.</p></div>
    </div>
    <div id="passkey-add-section">
        <div class="mb-2" id="passkey-label-input" style="display:none;">
            <input type="text" id="passkey-device-label" value="">
        </div>
        <button type="button" id="passkey-add-btn"></button>
    </div>
    <div id="passkey-unsupported-notice" class="d-none"></div>`;

// The server answers base64url; the file turns it into an ArrayBuffer
// before handing it to WebAuthn, and back again on the way out.
const CHALLENGE_B64 = 'AQID';       // bytes 1, 2, 3
const USER_ID_B64 = 'BAUG';         // bytes 4, 5, 6

// A FACTORY, not a shared constant: the file decodes the options in
// place (`options.challenge = base64ToBuffer(options.challenge)`), which
// is correct against a real fetch — every response parses a fresh
// object — but would let one test hand the next an already-decoded
// ArrayBuffer, and atob() would throw on it.
const registerOptions = (extra) => Object.assign({
    challenge: CHALLENGE_B64,
    user: { id: USER_ID_B64, name: 'jean@example.org' },
    rp: { name: 'ScoutMagic' },
}, extra || {});

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

function buffer(...bytes) {
    return Uint8Array.from(bytes).buffer;
}

function fakeCredential() {
    return {
        id: 'cred-abc',
        type: 'public-key',
        rawId: buffer(10, 11, 12),
        response: {
            attestationObject: buffer(20, 21),
            clientDataJSON: buffer(30, 31),
        },
    };
}

describe('account-passkeys.js', () => {
    let create;

    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn((url) =>
            String(url).includes('register-options')
                ? jsonResponse(registerOptions())
                : jsonResponse({ success: true })
        );
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
        window.PublicKeyCredential = function () {};
        create = vi.fn(() => Promise.resolve(fakeCredential()));
        Object.defineProperty(navigator, 'credentials', {
            configurable: true,
            value: { create },
        });
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/account', reload: vi.fn() },
        });
    });

    afterEach(() => {
        delete window.PublicKeyCredential;
    });

    async function boot() {
        // The real fetch toolbox — account-passkeys.js goes through
        // window.ScoutMagicApi (base.html.twig guarantees this load order
        // in production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/account-passkeys.js');
    }

    // Exact URL, not a substring: '/account/passkey/register' is a
    // prefix of '/account/passkey/register-options'.
    function requestTo(target) {
        const call = fetch.mock.calls.find(([url]) => String(url) === target);
        if (!call) {
            return null;
        }
        const [url, opts] = call;
        return { url, opts, body: opts && opts.body ? JSON.parse(opts.body) : null };
    }

    const addBtn = () => document.getElementById('passkey-add-btn');

    // The add button is a two-step control: the first click reveals the
    // name field, the second one registers. The handler is async, so the
    // two clicks need a turn of the event loop between them.
    async function clickTwice() {
        addBtn().click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        addBtn().click();
    }
    const deleteBtn = () => document.querySelector('.passkey-delete-btn');
    const errorBox = () => document.getElementById('passkey-error');

    describe('entry guard', () => {
        it('does nothing at all on a page with neither button', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('wires deletion even when the add section is absent', async () => {
            document.body.innerHTML = `<div id="passkey-error" class="d-none"></div>${KEY_ROW}`;
            await boot();
            deleteBtn().click();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(requestTo('/account/passkey/delete')).not.toBeNull();
        });
    });

    describe('unsupported browser', () => {
        it('disables the button and reveals the notice when WebAuthn is missing', async () => {
            delete window.PublicKeyCredential;
            await boot();

            expect(addBtn().disabled).toBe(true);
            expect(document.getElementById('passkey-unsupported-notice').classList.contains('d-none'))
                .toBe(false);
        });

        it('leaves the button alone when WebAuthn is there', async () => {
            await boot();

            expect(addBtn().disabled).toBe(false);
            expect(document.getElementById('passkey-unsupported-notice').classList.contains('d-none'))
                .toBe(true);
        });
    });

    describe('registration', () => {
        it('first click only reveals and focuses the name field — no request yet', async () => {
            await boot();
            addBtn().click();
            await Promise.resolve();

            expect(document.getElementById('passkey-label-input').style.display).toBe('block');
            expect(document.activeElement).toBe(document.getElementById('passkey-device-label'));
            expect(fetch).not.toHaveBeenCalled();
        });

        it('second click fetches the options and hands WebAuthn real buffers', async () => {
            await boot();
            addBtn().click();
            await new Promise((resolve) => setTimeout(resolve, 0));
            document.getElementById('passkey-device-label').value = 'Clé du bureau';
            addBtn().click();

            await vi.waitFor(() => expect(create).toHaveBeenCalled());
            const options = create.mock.calls[0][0].publicKey;
            expect(options.challenge).toBeInstanceOf(ArrayBuffer);
            expect([...new Uint8Array(options.challenge)]).toEqual([1, 2, 3]);
            expect([...new Uint8Array(options.user.id)]).toEqual([4, 5, 6]);
        });

        it('decodes excludeCredentials too — that list is what stops a duplicate', async () => {
            global.fetch = vi.fn((url) =>
                String(url).includes('register-options')
                    ? jsonResponse(registerOptions({ excludeCredentials: [{ id: 'AQID', type: 'public-key' }] }))
                    : jsonResponse({ success: true })
            );
            await boot();
            await clickTwice();

            await vi.waitFor(() => expect(create).toHaveBeenCalled());
            const excluded = create.mock.calls[0][0].publicKey.excludeCredentials[0];
            expect(excluded.id).toBeInstanceOf(ArrayBuffer);
            expect(excluded.type).toBe('public-key');
        });

        it('POSTs the credential base64url-encoded, with the label and the CSRF token', async () => {
            await boot();
            addBtn().click();
            await new Promise((resolve) => setTimeout(resolve, 0));
            document.getElementById('passkey-device-label').value = 'Clé du bureau';
            addBtn().click();

            await vi.waitFor(() => expect(requestTo('/account/passkey/register')).not.toBeNull());
            const { opts, body } = requestTo('/account/passkey/register');
            expect(opts.method).toBe('POST');
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
            expect(body._csrf_token).toBe('tok-123');
            expect(body.id).toBe('cred-abc');
            expect(body.device_label).toBe('Clé du bureau');
            expect(body.rawId).toBe('CgsM');
            expect(body.response.attestationObject).toBe('FBU');
            expect(body.response.clientDataJSON).toBe('Hh8');
        });

        it('falls back to « Clé sans nom » when the field was left empty', async () => {
            await boot();
            await clickTwice();

            await vi.waitFor(() => expect(requestTo('/account/passkey/register')).not.toBeNull());
            expect(requestTo('/account/passkey/register').body.device_label).toBe('Clé sans nom');
        });

        it('reloads the page once the server confirms', async () => {
            await boot();
            await clickTwice();

            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
        });

        it('does NOT reload on {success:false} answered with HTTP 200 — it says why', async () => {
            global.fetch = vi.fn((url) =>
                String(url).includes('register-options')
                    ? jsonResponse(registerOptions())
                    : jsonResponse({ success: false, error: 'Clé déjà connue.' })
            );
            await boot();
            await clickTwice();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show)
                .toHaveBeenCalledWith('Clé déjà connue.', { variant: 'error' });
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('stops before WebAuthn when the options request fails', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();
            await clickTwice();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(create).not.toHaveBeenCalled();
        });

        it.each([
            ['InvalidStateError', 'Cet appareil possède déjà une clé enregistrée pour ce compte.'],
            ['NotAllowedError', 'L\'enregistrement a été annulé.'],
            ['SomethingElse', 'L\'enregistrement a échoué.'],
        ])('turns a %s from the authenticator into its own French sentence', async (name, message) => {
            create.mockImplementation(() => Promise.reject(Object.assign(new Error('x'), { name })));
            await boot();
            await clickTwice();

            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(message, { variant: 'error' });
            expect(requestTo('/account/passkey/register')).toBeNull();
        });
    });

    describe('revocation', () => {
        it('asks first, in French, naming the consequence', async () => {
            await boot();
            deleteBtn().click();

            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
                message: 'Supprimer cette clé ? Vous ne pourrez plus vous connecter avec.',
                confirmLabel: 'Supprimer',
            });
        });

        it('sends NOTHING and keeps the row when the answer is no', async () => {
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            await boot();
            deleteBtn().click();

            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            expect(fetch).not.toHaveBeenCalled();
            expect(document.querySelector('[data-passkey-id="7"]')).not.toBeNull();
        });

        it('POSTs the id as a number and removes the row on a confirmed deletion', async () => {
            await boot();
            deleteBtn().click();

            await vi.waitFor(() => expect(document.querySelector('[data-passkey-id="7"]')).toBeNull());
            expect(requestTo('/account/passkey/delete').body)
                .toEqual({ id: 7, _csrf_token: 'tok-123' });
        });

        it('reveals the empty state once the last key is gone', async () => {
            await boot();
            deleteBtn().click();

            await vi.waitFor(() =>
                expect(document.getElementById('no-passkeys-msg').classList.contains('d-none')).toBe(false));
        });

        it('leaves the empty state hidden while another key remains', async () => {
            document.getElementById('passkey-list').insertAdjacentHTML('afterbegin', `
                <div data-passkey-id="8">
                    <button type="button" class="passkey-delete-btn" data-id="8"></button>
                </div>`);
            await boot();
            deleteBtn().click();

            await vi.waitFor(() => expect(document.querySelector('[data-passkey-id="8"]')).toBeNull());
            expect(document.getElementById('no-passkeys-msg').classList.contains('d-none')).toBe(true);
        });

        it('KEEPS the row on {success:false} answered with HTTP 200 — the key is still valid', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Clé introuvable.' }));
            await boot();
            deleteBtn().click();

            await vi.waitFor(() => expect(errorBox().classList.contains('d-none')).toBe(false));
            expect(document.querySelector('[data-passkey-id="7"]')).not.toBeNull();
            expect(errorBox().textContent).toBe('Clé introuvable.');
        });

        it('keeps the row and shows the generic failure when the network dies', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();
            deleteBtn().click();

            await vi.waitFor(() => expect(errorBox().classList.contains('d-none')).toBe(false));
            expect(document.querySelector('[data-passkey-id="7"]')).not.toBeNull();
            expect(errorBox().textContent)
                .toBe('La clé n\'a pas pu être supprimée. Rechargez la page et réessayez.');
        });

        it('clears a previous failure before trying again', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Clé introuvable.' }));
            await boot();
            deleteBtn().click();
            await vi.waitFor(() => expect(errorBox().textContent).toBe('Clé introuvable.'));

            global.fetch = vi.fn(() => jsonResponse({ success: true }));
            deleteBtn().click();
            await vi.waitFor(() => expect(document.querySelector('[data-passkey-id="7"]')).toBeNull());
            expect(errorBox().classList.contains('d-none')).toBe(true);
        });
    });
});
