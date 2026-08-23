// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked, and so are the two site-wide dialog
// toolboxes (window.ScoutMagicToast, window.ScoutMagicConfirm) base.html.twig
// loads on every page. Exercises the REAL implementation in
// public/assets/js/sos-config.js (imported below, never reimplemented here),
// on top of the real fetch toolbox public/assets/js/api.js — base.html.twig
// guarantees that load order in production.
//
// The page under test is the OVH telephony setup wizard
// (modules/sos_staff/views/config.html.twig): it carries the unit's provider
// credentials and, once a line is picked, its phone numbers. The properties
// this file protects are therefore mostly security ones:
//   - every step posts to its own endpoint, with the CSRF token, and the
//     credentials travel in the body — never in the URL;
//   - regenerating the Consumer Key asks first and sends NOTHING when the
//     answer is no;
//   - an HTTP error page and a {success:false} body are both failures, never
//     a silent success;
//   - an OVH error message containing markup is shown as text.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE_DOM = `
    <form id="ovh-credentials-form">
        <input type="password" id="ovh-application-key" value="">
        <button type="button" class="toggle-visibility" data-target="ovh-application-key"></button>
        <input type="password" id="ovh-application-secret" value="">
        <button type="button" class="toggle-visibility" data-target="ovh-application-secret"></button>
        <button type="submit">Enregistrer</button>
    </form>
    <div class="text-danger small mt-2 d-none" id="ovh-credentials-error" role="alert"></div>

    <button type="button" id="generate-consumer-key-btn">Générer une Consumer Key</button>
    <div id="consumer-key-validation" class="mt-2 d-none">
        <a href="#" id="consumer-key-validation-link"></a>
        <button type="button" id="validate-consumer-key-btn">J'ai validé, vérifier</button>
    </div>
    <button type="button" id="regenerate-consumer-key-btn">Régénérer la Consumer Key</button>
    <div class="text-danger small mt-2 d-none" id="consumer-key-error" role="alert"></div>

    <button type="button" id="list-lines-btn">Récupérer mes lignes OVH</button>
    <div class="d-none" id="line-picker-row">
        <select id="line-select"></select>
        <button type="button" id="select-line-btn">Sélectionner</button>
    </div>
    <div class="text-danger small mt-2 d-none" id="lines-error" role="alert"></div>

    <button type="button" id="test-connection-btn">Tester la connexion</button>
    <span id="test-connection-result" class="ms-2 small"></span>

    <form id="excluded-sections-form">
        <input class="form-check-input excluded-section-checkbox" type="checkbox" id="excluded-section-1" value="1" checked>
        <input class="form-check-input excluded-section-checkbox" type="checkbox" id="excluded-section-2" value="2">
        <input class="form-check-input excluded-section-checkbox" type="checkbox" id="excluded-section-3" value="3" disabled>
    </form>`;

/** The site-wide envelope for a JSON answer the server really sent. */
function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

/** A 500 that is an HTML error page, not JSON — the shape api.js folds to data:null. */
function htmlErrorResponse() {
    return Promise.resolve({ ok: false, status: 500, json: () => Promise.reject(new Error('not json')) });
}

/**
 * A fetch double answering each URL from a map of JSON bodies. Anything not
 * listed answers `{success: true}` — an unexpected call still shows up in
 * fetch.mock.calls, which is what the assertions read.
 *
 * @param {Object.<string, any>} bodiesByUrlFragment
 */
function mockFetch(bodiesByUrlFragment) {
    return vi.fn((url) => {
        const match = Object.keys(bodiesByUrlFragment).find((fragment) => String(url).includes(fragment));
        return jsonResponse(match ? bodiesByUrlFragment[match] : { success: true });
    });
}

function click(id) {
    document.getElementById(id).dispatchEvent(new Event('click', { bubbles: true }));
}

function submitCredentials() {
    document.getElementById('ovh-credentials-form')
        .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

function lastRequest() {
    const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
    return { url, opts, body: JSON.parse(opts.body) };
}

describe('sos-config.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE_DOM;
        global.fetch = mockFetch({});
        window.ScoutMagicToast = { show: vi.fn() };
        window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
        // Several steps reload on success; jsdom does not implement
        // navigation and would log "Not implemented".
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: 'http://localhost/config/sos', reload: vi.fn() },
        });
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/sos-config.js');
    }

    describe('entry guard', () => {
        it('does nothing at all on a page carrying none of its controls', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('survives a page that has the form but none of the wizard buttons', async () => {
            document.body.innerHTML = '<form id="ovh-credentials-form"><button type="submit"></button></form>';
            await expect(boot()).resolves.not.toThrow();
        });
    });

    describe('step 1 — Application Key / Secret', () => {
        it('POSTs both credentials and the CSRF token to the credentials endpoint', async () => {
            await boot();
            document.getElementById('ovh-application-key').value = 'app-key';
            document.getElementById('ovh-application-secret').value = 'app-secret';

            submitCredentials();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { url, opts, body } = lastRequest();
            expect(url).toBe('/config/sos/ovh/credentials');
            expect(opts.method).toBe('POST');
            expect(body).toEqual({
                application_key: 'app-key',
                application_secret: 'app-secret',
                _csrf_token: 'tok-123',
            });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
        });

        it('never puts a credential in the URL it builds', async () => {
            await boot();
            document.getElementById('ovh-application-key').value = 'app-key';
            document.getElementById('ovh-application-secret').value = 'app-secret';

            submitCredentials();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const url = String(fetch.mock.calls[0][0]);
            expect(url).not.toContain('app-key');
            expect(url).not.toContain('app-secret');
        });

        it('reloads once the credentials are stored', async () => {
            await boot();

            submitCredentials();
            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
        });

        it('shows the server error inline instead of reloading', async () => {
            global.fetch = mockFetch({ '/credentials': { success: false, error: 'Clé refusée par OVH.' } });
            await boot();

            submitCredentials();
            await vi.waitFor(() => {
                expect(document.getElementById('ovh-credentials-error').textContent).toBe('Clé refusée par OVH.');
            });
            expect(document.getElementById('ovh-credentials-error').classList.contains('d-none')).toBe(false);
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('treats an HTTP 500 error page as a failure, not a success', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            submitCredentials();
            await vi.waitFor(() => {
                expect(document.getElementById('ovh-credentials-error').textContent)
                    .toBe('Erreur : réponse serveur invalide.');
            });
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('toggles a credential field between hidden and visible', async () => {
            await boot();
            const field = document.getElementById('ovh-application-key');

            document.querySelector('.toggle-visibility').dispatchEvent(new Event('click', { bubbles: true }));
            expect(field.type).toBe('text');

            document.querySelector('.toggle-visibility').dispatchEvent(new Event('click', { bubbles: true }));
            expect(field.type).toBe('password');
        });
    });

    describe('step 2 — Consumer Key', () => {
        it('POSTs the generation request and shows the validation link OVH returned', async () => {
            global.fetch = mockFetch({
                '/generate-consumer-key': { success: true, validation_url: 'https://eu.api.ovh.com/auth/?credentialToken=abc' },
            });
            await boot();

            click('generate-consumer-key-btn');
            await vi.waitFor(() => {
                expect(document.getElementById('consumer-key-validation').classList.contains('d-none')).toBe(false);
            });

            expect(lastRequest().url).toBe('/config/sos/ovh/generate-consumer-key');
            const link = document.getElementById('consumer-key-validation-link');
            expect(link.getAttribute('href')).toBe('https://eu.api.ovh.com/auth/?credentialToken=abc');
            expect(link.textContent).toBe('https://eu.api.ovh.com/auth/?credentialToken=abc');
        });

        it('refuses a validation URL that is not a web link', async () => {
            global.fetch = mockFetch({
                '/generate-consumer-key': { success: true, validation_url: 'javascript:alert(1)' },
            });
            await boot();

            click('generate-consumer-key-btn');
            await vi.waitFor(() => {
                expect(document.getElementById('consumer-key-error').textContent).not.toBe('');
            });

            expect(document.getElementById('consumer-key-validation-link').getAttribute('href')).toBe('#');
            expect(document.getElementById('consumer-key-validation').classList.contains('d-none')).toBe(true);
        });

        it('reports a generation failure inline and shows no link', async () => {
            global.fetch = mockFetch({
                '/generate-consumer-key': { success: false, error: 'Application inconnue.' },
            });
            await boot();

            click('generate-consumer-key-btn');
            await vi.waitFor(() => {
                expect(document.getElementById('consumer-key-error').textContent).toBe('Application inconnue.');
            });
            expect(document.getElementById('consumer-key-validation').classList.contains('d-none')).toBe(true);
        });

        it('POSTs the validation check and reloads on success', async () => {
            await boot();

            click('validate-consumer-key-btn');
            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());

            expect(lastRequest().url).toBe('/config/sos/ovh/validate-consumer-key');
            expect(lastRequest().body).toEqual({ _csrf_token: 'tok-123' });
        });

        it('shows the validation error inline instead of reloading', async () => {
            global.fetch = mockFetch({
                '/validate-consumer-key': { success: false, error: 'Consumer Key pas encore validée.' },
            });
            await boot();

            click('validate-consumer-key-btn');
            await vi.waitFor(() => {
                expect(document.getElementById('consumer-key-error').textContent)
                    .toBe('Consumer Key pas encore validée.');
            });
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('asks before regenerating, naming the act on the confirm button', async () => {
            await boot();

            click('regenerate-consumer-key-btn');
            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());

            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
                message: 'Régénérer la Consumer Key ? La ligne devra être sélectionnée à nouveau.',
                confirmLabel: 'Régénérer',
            });
        });

        it('sends NOTHING when the regeneration is refused', async () => {
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            await boot();

            click('regenerate-consumer-key-btn');
            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            await Promise.resolve();

            expect(fetch).not.toHaveBeenCalled();
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('regenerates and reloads once confirmed', async () => {
            await boot();

            click('regenerate-consumer-key-btn');
            await vi.waitFor(() => expect(window.location.reload).toHaveBeenCalled());
            expect(lastRequest().url).toBe('/config/sos/ovh/generate-consumer-key');
        });

        it('toasts a failed regeneration rather than opening a native alert', async () => {
            global.fetch = mockFetch({
                '/generate-consumer-key': { success: false, error: 'Quota OVH atteint.' },
            });
            await boot();

            click('regenerate-consumer-key-btn');
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Quota OVH atteint.', { variant: 'error' });
            expect(window.location.reload).not.toHaveBeenCalled();
        });
    });

    describe('step 3 — phone line', () => {
        const LINES = {
            success: true,
            lines: [
                { billing_account: 'ab123456-1', service_name: '0032123456', number: '+32 2 123 45 67' },
                { billing_account: 'ab123456-2', service_name: '0032987654', number: '+32 2 987 65 43' },
            ],
        };

        it('lists the OVH lines and reveals the picker', async () => {
            global.fetch = mockFetch({ '/list-lines': LINES });
            await boot();

            click('list-lines-btn');
            await vi.waitFor(() => {
                expect(document.querySelectorAll('#line-select option')).toHaveLength(2);
            });

            expect(lastRequest().url).toBe('/config/sos/ovh/list-lines');
            expect(document.getElementById('line-picker-row').classList.contains('d-none')).toBe(false);
            const options = document.querySelectorAll('#line-select option');
            expect(options[0].textContent).toBe('ab123456-1 — +32 2 123 45 67');
            expect(options[0].dataset.billingAccount).toBe('ab123456-1');
            expect(options[0].dataset.serviceName).toBe('0032123456');
        });

        it('renders a line label containing markup as text', async () => {
            global.fetch = mockFetch({
                '/list-lines': {
                    success: true,
                    lines: [{ billing_account: '<img src=x onerror=alert(1)>', service_name: 's1', number: '+32 2 000 00 00' }],
                },
            });
            await boot();

            click('list-lines-btn');
            await vi.waitFor(() => {
                expect(document.querySelectorAll('#line-select option')).toHaveLength(1);
            });

            expect(document.querySelector('#line-select img')).toBeNull();
            expect(document.querySelector('#line-select option').textContent)
                .toContain('<img src=x onerror=alert(1)>');
        });

        it('reports a listing failure inline and leaves the picker hidden', async () => {
            global.fetch = mockFetch({ '/list-lines': { success: false, error: 'Consumer Key invalide.' } });
            await boot();

            click('list-lines-btn');
            await vi.waitFor(() => {
                expect(document.getElementById('lines-error').textContent).toBe('Consumer Key invalide.');
            });
            expect(document.getElementById('line-picker-row').classList.contains('d-none')).toBe(true);
        });

        it('POSTs the chosen line only on the explicit « Sélectionner » click', async () => {
            global.fetch = mockFetch({ '/list-lines': LINES });
            await boot();

            click('list-lines-btn');
            await vi.waitFor(() => expect(document.querySelectorAll('#line-select option')).toHaveLength(2));

            // Browsing the dropdown must not select anything server-side.
            const select = document.getElementById('line-select');
            select.value = '0032987654';
            select.dispatchEvent(new Event('change', { bubbles: true }));
            await Promise.resolve();
            expect(fetch).toHaveBeenCalledTimes(1);

            click('select-line-btn');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));

            const { url, body } = lastRequest();
            expect(url).toBe('/config/sos/ovh/select-line');
            expect(body).toEqual({
                billing_account: 'ab123456-2',
                service_name: '0032987654',
                _csrf_token: 'tok-123',
            });
        });

        it('sends nothing when no line has been listed yet', async () => {
            await boot();

            click('select-line-btn');
            await Promise.resolve();

            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('connection test', () => {
        it('shows a success line without ever parsing it as markup', async () => {
            await boot();

            click('test-connection-btn');
            await vi.waitFor(() => {
                expect(document.getElementById('test-connection-result').textContent).toContain('Connexion réussie');
            });

            expect(lastRequest().url).toBe('/config/sos/test-connection');
            expect(document.querySelector('#test-connection-result .text-success')).not.toBeNull();
        });

        it('renders a provider error containing markup as text, not as HTML', async () => {
            global.fetch = mockFetch({
                '/test-connection': { success: false, error: '<img src=x onerror=alert(1)> Échec' },
            });
            await boot();

            click('test-connection-btn');
            await vi.waitFor(() => {
                expect(document.getElementById('test-connection-result').textContent)
                    .toContain('<img src=x onerror=alert(1)>');
            });

            expect(document.querySelector('#test-connection-result img')).toBeNull();
            expect(document.querySelector('#test-connection-result .text-danger')).not.toBeNull();
        });

        it('reports an HTTP 500 error page as a failure', async () => {
            global.fetch = vi.fn(() => htmlErrorResponse());
            await boot();

            click('test-connection-btn');
            await vi.waitFor(() => {
                expect(document.getElementById('test-connection-result').textContent)
                    .toContain('Erreur : réponse serveur invalide.');
            });
            expect(document.querySelector('#test-connection-result .text-success')).toBeNull();
        });
    });

    describe('sections included in the planning', () => {
        it('POSTs the unchecked, non-disabled sections as the excluded ones', async () => {
            await boot();

            const checkbox = document.getElementById('excluded-section-1');
            checkbox.checked = false;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { url, body } = lastRequest();
            expect(url).toBe('/config/sos/excluded-sections');
            // 2 is unchecked, 3 is unchecked but disabled (Staff d'U).
            expect(body).toEqual({ section_ids: [1, 2], _csrf_token: 'tok-123' });
        });

        it('toasts a refused change instead of leaving the box looking saved', async () => {
            global.fetch = mockFetch({ '/excluded-sections': { success: false, error: 'Section inconnue.' } });
            await boot();

            const checkbox = document.getElementById('excluded-section-2');
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Section inconnue.', { variant: 'error' });
        });
    });
});
