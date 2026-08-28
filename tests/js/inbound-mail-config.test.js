// Isolated JavaScript unit test — jsdom-simulated DOM only.
// Exercises the REAL implementation in
// public/assets/js/inbound-mail-config.js on top of the real fetch toolbox
// public/assets/js/api.js.
//
// What this file protects:
//   - the test button sends the form values as a JSON body (never in the URL);
//   - a successful test populates the folder checkboxes and hides the textarea;
//   - edit mode fills the form, switches labels, and cancel resets everything;
//   - a failed test shows an error and does not touch the folder picker;
//   - no credential is ever put into a URL.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE_DOM = `
    <h2 class="h5 mb-3" id="mailbox-form-title">Ajouter une boîte</h2>
    <form method="post" action="/config/courrier-entrant/boites" class="row g-3" id="mailbox-form">
        <input type="hidden" name="_csrf_token" value="tok-csrf">
        <input type="hidden" name="id" id="mailbox-id" value="0">
        <input id="mailbox-name" name="name" value="">
        <input id="mailbox-username" name="username" value="">
        <input id="mailbox-host" name="host" value="">
        <input id="mailbox-port" name="port" type="number" value="993">
        <select id="mailbox-encryption" name="encryption">
            <option value="ssl" selected>SSL</option>
            <option value="tls">TLS</option>
            <option value="starttls">STARTTLS</option>
        </select>
        <input type="password" id="mailbox-password" name="password" required>

        <button type="button" id="test-connection-btn">Tester la connexion</button>
        <span id="test-connection-result"></span>

        <div id="mailbox-folders-wrapper">
            <textarea id="mailbox-folders" name="folders"></textarea>
        </div>
        <div id="mailbox-folders-picker" class="d-none">
            <span class="form-label d-block mb-1">Dossiers surveillés</span>
            <div id="mailbox-folders-list"></div>
        </div>

        <input type="checkbox" id="mailbox-enabled" name="is_enabled" checked>
        <button type="submit" id="mailbox-submit-btn">Ajouter la boîte</button>
        <button type="button" class="d-none" id="mailbox-cancel-btn">Annuler</button>
    </form>

    <button type="button" class="js-edit-mailbox"
            data-id="42"
            data-name="Locations"
            data-host="imap.gmail.com"
            data-port="993"
            data-encryption="ssl"
            data-username="locs@unite.be"
            data-folders="INBOX&#10;Sent"
            data-is-enabled="1">Modifier</button>
`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

function mockFetch(bodiesByUrlFragment) {
    return vi.fn((url) => {
        const match = Object.keys(bodiesByUrlFragment).find((f) => String(url).includes(f));
        return jsonResponse(match ? bodiesByUrlFragment[match] : { success: true });
    });
}

function click(el) {
    (typeof el === 'string' ? document.getElementById(el) : el)
        .dispatchEvent(new Event('click', { bubbles: true }));
}

function lastRequest() {
    const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
    return { url, opts, body: JSON.parse(opts.body) };
}

describe('inbound-mail-config.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-csrf">';
        document.body.innerHTML = PAGE_DOM;
        global.fetch = mockFetch({});
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/inbound-mail-config.js');
    }

    describe('entry guard', () => {
        it('does nothing on a page without the form', async () => {
            document.body.innerHTML = '<p>Pas la bonne page</p>';
            await expect(boot()).resolves.not.toThrow();
        });
    });

    describe('test connection', () => {
        it('POSTs form values as JSON to the test endpoint', async () => {
            await boot();
            document.getElementById('mailbox-host').value = 'imap.test';
            document.getElementById('mailbox-port').value = '993';
            document.getElementById('mailbox-username').value = 'user@test';
            document.getElementById('mailbox-password').value = 'secret';

            click('test-connection-btn');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { url, body } = lastRequest();
            expect(url).toBe('/config/courrier-entrant/test-connexion');
            expect(body.host).toBe('imap.test');
            expect(body.username).toBe('user@test');
            expect(body.password).toBe('secret');
            expect(body._csrf_token).toBe('tok-csrf');
        });

        it('never puts a credential in the URL', async () => {
            await boot();
            document.getElementById('mailbox-password').value = 'my-secret';

            click('test-connection-btn');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { url } = lastRequest();
            expect(url).not.toContain('my-secret');
        });

        it('populates folder checkboxes on success', async () => {
            global.fetch = mockFetch({
                'test-connexion': { success: true, message: 'OK', folders: ['INBOX', 'Sent', 'Drafts'] },
            });
            await boot();
            document.getElementById('mailbox-host').value = 'imap.test';
            document.getElementById('mailbox-username').value = 'u@t';
            document.getElementById('mailbox-password').value = 'p';

            click('test-connection-btn');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            // Wait for DOM updates (async handler).
            await vi.waitFor(() => {
                const picker = document.getElementById('mailbox-folders-picker');
                return expect(picker.classList.contains('d-none')).toBe(false);
            });

            const checkboxes = document.querySelectorAll('.folder-checkbox');
            expect(checkboxes.length).toBe(3);
            expect(checkboxes[0].value).toBe('INBOX');
            expect(checkboxes[1].value).toBe('Sent');

            // The textarea wrapper is hidden.
            expect(document.getElementById('mailbox-folders-wrapper').classList.contains('d-none')).toBe(true);
        });

        it('pre-checks folders that were already selected', async () => {
            global.fetch = mockFetch({
                'test-connexion': { success: true, message: 'OK', folders: ['INBOX', 'Sent', 'Drafts'] },
            });
            await boot();
            document.getElementById('mailbox-folders').value = 'INBOX\nDrafts';
            document.getElementById('mailbox-host').value = 'h';
            document.getElementById('mailbox-username').value = 'u';
            document.getElementById('mailbox-password').value = 'p';

            click('test-connection-btn');
            await vi.waitFor(() => {
                return expect(document.querySelectorAll('.folder-checkbox').length).toBe(3);
            });

            const cbs = document.querySelectorAll('.folder-checkbox');
            expect(cbs[0].checked).toBe(true);  // INBOX
            expect(cbs[1].checked).toBe(false); // Sent
            expect(cbs[2].checked).toBe(true);  // Drafts
        });

        it('syncs checked folders back into the textarea', async () => {
            global.fetch = mockFetch({
                'test-connexion': { success: true, message: 'OK', folders: ['INBOX', 'Sent'] },
            });
            await boot();
            document.getElementById('mailbox-host').value = 'h';
            document.getElementById('mailbox-username').value = 'u';
            document.getElementById('mailbox-password').value = 'p';

            click('test-connection-btn');
            await vi.waitFor(() => {
                return expect(document.querySelectorAll('.folder-checkbox').length).toBe(2);
            });

            // Check the second folder.
            const sent = document.querySelectorAll('.folder-checkbox')[1];
            sent.checked = true;
            sent.dispatchEvent(new Event('change', { bubbles: true }));

            expect(document.getElementById('mailbox-folders').value).toContain('Sent');
        });

        it('shows an error on failure without touching the folder picker', async () => {
            global.fetch = mockFetch({
                'test-connexion': { success: false, error: 'Connexion refusée.' },
            });
            await boot();
            document.getElementById('mailbox-host').value = 'h';
            document.getElementById('mailbox-username').value = 'u';
            document.getElementById('mailbox-password').value = 'p';

            click('test-connection-btn');
            await vi.waitFor(() => {
                const result = document.getElementById('test-connection-result');
                return expect(result.textContent).toContain('Connexion refusée.');
            });

            // Picker stays hidden.
            expect(document.getElementById('mailbox-folders-picker').classList.contains('d-none')).toBe(true);
        });

        it('shows a success icon and message on success', async () => {
            global.fetch = mockFetch({
                'test-connexion': { success: true, message: 'Connexion réussie. 3 dossier(s) visible(s).', folders: ['INBOX'] },
            });
            await boot();
            document.getElementById('mailbox-host').value = 'h';
            document.getElementById('mailbox-username').value = 'u';
            document.getElementById('mailbox-password').value = 'p';

            click('test-connection-btn');
            await vi.waitFor(() => {
                const result = document.getElementById('test-connection-result');
                return expect(result.textContent).toContain('Connexion réussie');
            });

            expect(document.querySelector('#test-connection-result .text-success')).not.toBeNull();
        });
    });

    describe('edit mode', () => {
        it('fills the form from the edit button data attributes', async () => {
            await boot();
            click(document.querySelector('.js-edit-mailbox'));

            expect(document.getElementById('mailbox-id').value).toBe('42');
            expect(document.getElementById('mailbox-name').value).toBe('Locations');
            expect(document.getElementById('mailbox-host').value).toBe('imap.gmail.com');
            expect(document.getElementById('mailbox-port').value).toBe('993');
            expect(document.getElementById('mailbox-encryption').value).toBe('ssl');
            expect(document.getElementById('mailbox-username').value).toBe('locs@unite.be');
            expect(document.getElementById('mailbox-folders').value).toBe('INBOX\nSent');
            expect(document.getElementById('mailbox-enabled').checked).toBe(true);
        });

        it('switches the title and button text', async () => {
            await boot();
            click(document.querySelector('.js-edit-mailbox'));

            expect(document.getElementById('mailbox-form-title').textContent).toBe('Modifier la boîte');
            expect(document.getElementById('mailbox-submit-btn').textContent).toBe('Enregistrer');
        });

        it('shows the cancel button', async () => {
            await boot();
            click(document.querySelector('.js-edit-mailbox'));

            expect(document.getElementById('mailbox-cancel-btn').classList.contains('d-none')).toBe(false);
        });

        it('removes the required attribute from the password field', async () => {
            await boot();
            click(document.querySelector('.js-edit-mailbox'));

            expect(document.getElementById('mailbox-password').hasAttribute('required')).toBe(false);
        });

        it('sends the mailbox_id when testing an existing box', async () => {
            await boot();
            click(document.querySelector('.js-edit-mailbox'));
            document.getElementById('mailbox-password').value = '';
            document.getElementById('mailbox-host').value = 'imap.gmail.com';
            document.getElementById('mailbox-username').value = 'locs@unite.be';

            click('test-connection-btn');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { body } = lastRequest();
            expect(body.mailbox_id).toBe(42);
        });
    });

    describe('cancel edit', () => {
        it('resets the form back to add mode', async () => {
            await boot();
            click(document.querySelector('.js-edit-mailbox'));
            click('mailbox-cancel-btn');

            expect(document.getElementById('mailbox-id').value).toBe('0');
            expect(document.getElementById('mailbox-form-title').textContent).toBe('Ajouter une boîte');
            expect(document.getElementById('mailbox-submit-btn').textContent).toBe('Ajouter la boîte');
            expect(document.getElementById('mailbox-cancel-btn').classList.contains('d-none')).toBe(true);
            expect(document.getElementById('mailbox-password').hasAttribute('required')).toBe(true);
        });

        it('hides the folder picker and restores the textarea wrapper', async () => {
            global.fetch = mockFetch({
                'test-connexion': { success: true, message: 'OK', folders: ['INBOX'] },
            });
            await boot();
            click(document.querySelector('.js-edit-mailbox'));
            document.getElementById('mailbox-host').value = 'h';
            document.getElementById('mailbox-username').value = 'u';
            document.getElementById('mailbox-password').value = 'p';

            click('test-connection-btn');
            await vi.waitFor(() => {
                return expect(document.querySelectorAll('.folder-checkbox').length).toBe(1);
            });

            click('mailbox-cancel-btn');

            expect(document.getElementById('mailbox-folders-picker').classList.contains('d-none')).toBe(true);
            expect(document.getElementById('mailbox-folders-wrapper').classList.contains('d-none')).toBe(false);
        });
    });
});
