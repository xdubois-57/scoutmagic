// Isolated JavaScript unit test — jsdom-simulated DOM only.
// Exercises the REAL implementation in
// public/assets/js/inbound-mail-config.js on top of the real fetch toolbox
// public/assets/js/api.js.
//
// What this file protects:
//   - a typed address is sent to the provider endpoint in a JSON body,
//     never in the URL, and a recognised provider prefills an EMPTY
//     server, port and encryption, shows « X reconnu » and the
//     app-password help that applies (D8);
//   - a server the operator typed is never overwritten, and an unknown
//     provider empties only what the script itself had prefilled;
//   - the module field shows only for an address reserved to a module;
//   - opening « Dossiers lus » lists the server's folders as checkboxes
//     backed by the textarea, keeps what was chosen, and names the sent
//     folder found by the server (D9); a failure says why and touches
//     nothing;
//   - no credential is ever put into a URL.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE_DOM = `
    <form method="post" action="/config/courrier-entrant/boites" id="mailbox-form">
        <input type="hidden" name="id" id="mailbox-id" value="0">
        <input id="mailbox-username" name="username" type="email" value="">
        <p id="mailbox-provider-known" hidden><span class="badge"> reconnu</span> Les réglages de connexion sont remplis.</p>
        <p id="mailbox-provider-unknown" hidden>Fournisseur non reconnu</p>
        <input type="password" id="mailbox-password" name="password" required>
        <p data-password-help="gmail" hidden>Gmail</p>
        <p data-password-help="outlook" hidden>Outlook</p>
        <input id="mailbox-host" name="host" value="">
        <input id="mailbox-port" name="port" type="number" value="">
        <select id="mailbox-encryption" name="encryption">
            <option value="" selected>Choisir…</option>
            <option value="ssl">SSL</option>
            <option value="tls">TLS</option>
            <option value="starttls">STARTTLS</option>
        </select>
        <input type="radio" name="purpose" value="shared" id="mailbox-purpose-shared" checked>
        <input type="radio" name="purpose" value="dedicated" id="mailbox-purpose-dedicated">
        <div id="mailbox-module-field"><select id="mailbox-dedicated-to" name="dedicated_to"></select></div>

        <details id="mailbox-folders-details">
            <summary>Dossiers lus (facultatif)</summary>
            <div id="mailbox-folders-wrapper">
                <textarea id="mailbox-folders" name="folders"></textarea>
            </div>
            <div id="mailbox-folders-picker" class="d-none">
                <div id="mailbox-folders-list"></div>
            </div>
            <output id="mailbox-folders-status" aria-live="polite"></output>
            <p id="mailbox-sent-found" hidden>Trouvé automatiquement : <span id="mailbox-sent-found-name"></span></p>
            <div id="mailbox-sent-field"><input id="mailbox-sent-folder" name="sent_folder" value=""></div>
        </details>
    </form>
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

function $(id) {
    return document.getElementById(id);
}

function type(id, value) {
    $(id).value = value;
    $(id).dispatchEvent(new Event('change', { bubbles: true }));
}

function openFolders() {
    $('mailbox-folders-details').open = true;
    $('mailbox-folders-details').dispatchEvent(new Event('toggle'));
}

function lastRequest() {
    const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
    return { url, opts, body: JSON.parse(opts.body) };
}

const GMAIL = {
    recognized: true,
    provider: { key: 'gmail', label: 'Gmail', host: 'imap.gmail.com', port: 993, encryption: 'ssl', password_help: 'gmail' },
};

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

    it('does nothing on a page without the form', async () => {
        document.body.innerHTML = '<p>Pas la bonne page</p>';
        await expect(boot()).resolves.not.toThrow();
    });

    describe('provider recognition', () => {
        it('POSTs the address as JSON, never in the URL', async () => {
            await boot();
            type('mailbox-username', 'contact.unite@gmail.com');
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const { url, body } = lastRequest();
            expect(url).toBe('/config/courrier-entrant/fournisseur');
            expect(url).not.toContain('gmail');
            expect(body.address).toBe('contact.unite@gmail.com');
            expect(body._csrf_token).toBe('tok-csrf');
        });

        it('prefills empty settings and shows the help of a recognised provider', async () => {
            global.fetch = mockFetch({ fournisseur: GMAIL });
            await boot();
            type('mailbox-username', 'contact.unite@gmail.com');

            await vi.waitFor(() => expect($('mailbox-host').value).toBe('imap.gmail.com'));
            expect($('mailbox-port').value).toBe('993');
            expect($('mailbox-encryption').value).toBe('ssl');
            expect($('mailbox-provider-known').hidden).toBe(false);
            expect($('mailbox-provider-known').textContent).toContain('Gmail reconnu');
            expect($('mailbox-provider-unknown').hidden).toBe(true);
            expect(document.querySelector('[data-password-help="gmail"]').hidden).toBe(false);
            expect(document.querySelector('[data-password-help="outlook"]').hidden).toBe(true);
        });

        it('never overwrites a server the operator typed', async () => {
            global.fetch = mockFetch({ fournisseur: GMAIL });
            await boot();
            $('mailbox-host').value = 'imap.mon-serveur.be';
            type('mailbox-username', 'contact.unite@gmail.com');

            await vi.waitFor(() => expect($('mailbox-provider-known').hidden).toBe(false));
            expect($('mailbox-host').value).toBe('imap.mon-serveur.be');
        });

        it('leaves the settings empty for an unknown provider, clearing only its own prefill', async () => {
            global.fetch = mockFetch({ fournisseur: GMAIL });
            await boot();
            type('mailbox-username', 'contact.unite@gmail.com');
            await vi.waitFor(() => expect($('mailbox-host').value).toBe('imap.gmail.com'));

            global.fetch = mockFetch({ fournisseur: { recognized: false, provider: null } });
            type('mailbox-username', 'unite@exemple-hebergeur.be');

            await vi.waitFor(() => expect($('mailbox-provider-unknown').hidden).toBe(false));
            expect($('mailbox-host').value).toBe('');
            expect($('mailbox-port').value).toBe('');
            expect($('mailbox-encryption').value).toBe('');
            expect($('mailbox-provider-known').hidden).toBe(true);
            expect(document.querySelector('[data-password-help="gmail"]').hidden).toBe(true);
        });

        it('does not ask about something that is not an address', async () => {
            await boot();
            type('mailbox-username', 'pas une adresse');
            await Promise.resolve();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('usage', () => {
        it('shows the module field only for a reserved address', async () => {
            await boot();
            expect($('mailbox-module-field').hidden).toBe(true);

            $('mailbox-purpose-dedicated').checked = true;
            $('mailbox-purpose-dedicated').dispatchEvent(new Event('change', { bubbles: true }));
            expect($('mailbox-module-field').hidden).toBe(false);
        });
    });

    describe('folders read', () => {
        function fillConnection() {
            $('mailbox-username').value = 'u@unite.be';
            $('mailbox-host').value = 'imap.unite.be';
            $('mailbox-port').value = '993';
            $('mailbox-encryption').value = 'ssl';
            $('mailbox-password').value = 'secret';
        }

        it('asks for the connection first rather than calling the server', async () => {
            await boot();
            openFolders();
            expect($('mailbox-folders-status').textContent).toContain('Renseignez');
            expect(fetch).not.toHaveBeenCalled();
        });

        it('lists the offered folders, keeps the chosen ones, and names the sent folder', async () => {
            global.fetch = mockFetch({
                'test-connexion': { success: true, folders: ['Factures', 'Locations'], sent_folder: '[Gmail]/Messages envoyés' },
            });
            await boot();
            fillConnection();
            $('mailbox-folders').value = 'Locations';
            openFolders();

            await vi.waitFor(() => expect(document.querySelectorAll('.folder-checkbox').length).toBe(2));
            const { url, body } = lastRequest();
            expect(url).toBe('/config/courrier-entrant/test-connexion');
            expect(url).not.toContain('secret');
            expect(body.password).toBe('secret');

            const boxes = document.querySelectorAll('.folder-checkbox');
            expect(boxes[0].checked).toBe(false);
            expect(boxes[1].checked).toBe(true);
            expect($('mailbox-folders-wrapper').classList.contains('d-none')).toBe(true);
            expect($('mailbox-sent-found').hidden).toBe(false);
            expect($('mailbox-sent-found-name').textContent).toBe('[Gmail]/Messages envoyés');
            expect($('mailbox-sent-field').hidden).toBe(true);

            boxes[0].checked = true;
            boxes[0].dispatchEvent(new Event('change', { bubbles: true }));
            expect($('mailbox-folders').value).toBe('Factures\nLocations');
        });

        it('keeps a chosen folder the server no longer offers, and says which module the box is for', async () => {
            global.fetch = mockFetch({ 'test-connexion': { success: true, folders: ['Factures'], sent_folder: null } });
            await boot();
            fillConnection();
            $('mailbox-folders').value = 'Spam';
            $('mailbox-dedicated-to').innerHTML = '<option value="core_mail_seed" selected>Témoins</option>';
            $('mailbox-purpose-dedicated').checked = true;
            openFolders();

            await vi.waitFor(() => expect(document.querySelectorAll('.folder-checkbox').length).toBe(2));
            expect(lastRequest().body.dedicated_to).toBe('core_mail_seed');
            const boxes = document.querySelectorAll('.folder-checkbox');
            expect(boxes[1].value).toBe('Spam');
            expect(boxes[1].checked).toBe(true);
            expect($('mailbox-folders').value).toBe('Spam');
        });

        it('keeps an operator-named sent folder editable', async () => {
            global.fetch = mockFetch({ 'test-connexion': { success: true, folders: [], sent_folder: 'Sent' } });
            await boot();
            fillConnection();
            $('mailbox-sent-folder').value = 'Éléments envoyés';
            openFolders();

            await vi.waitFor(() => expect($('mailbox-folders-status').textContent).toContain("pas d'autre dossier"));
            expect($('mailbox-sent-field').hidden).toBe(false);
            expect($('mailbox-sent-found').hidden).toBe(true);
        });

        it('says why on failure and touches nothing', async () => {
            global.fetch = mockFetch({ 'test-connexion': { success: false, error: 'Connexion refusée.' } });
            await boot();
            fillConnection();
            openFolders();

            await vi.waitFor(() => expect($('mailbox-folders-status').textContent).toBe('Connexion refusée.'));
            expect($('mailbox-folders-picker').classList.contains('d-none')).toBe(true);
        });

        it('reads the folders again for other settings, and drops what it showed for the old ones', async () => {
            global.fetch = mockFetch({
                'test-connexion': { success: true, folders: ['Factures'], sent_folder: 'Envoyés' },
            });
            await boot();
            fillConnection();
            openFolders();
            await vi.waitFor(() => expect(document.querySelectorAll('.folder-checkbox').length).toBe(1));
            expect($('mailbox-sent-found').hidden).toBe(false);

            // Reopening with the same settings asks nothing again.
            openFolders();
            expect(fetch).toHaveBeenCalledTimes(1);

            global.fetch = mockFetch({ 'test-connexion': { success: true, folders: ['Archives'], sent_folder: null } });
            type('mailbox-host', 'imap.ailleurs.be');

            await vi.waitFor(() => expect(document.querySelector('.folder-checkbox')?.value).toBe('Archives'));
            expect(lastRequest().body.host).toBe('imap.ailleurs.be');
            expect($('mailbox-sent-found').hidden).toBe(true);
            expect($('mailbox-sent-field').hidden).toBe(false);
        });

        it('lists again when the usage changes, since a seed box is offered its junk folder', async () => {
            global.fetch = mockFetch({ 'test-connexion': { success: true, folders: [], sent_folder: null } });
            await boot();
            fillConnection();
            $('mailbox-dedicated-to').innerHTML = '<option value="core_mail_seed" selected>Témoins</option>';
            openFolders();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));

            $('mailbox-purpose-dedicated').checked = true;
            $('mailbox-purpose-dedicated').dispatchEvent(new Event('change', { bubbles: true }));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
            expect(lastRequest().body.dedicated_to).toBe('core_mail_seed');
        });

        it('ignores a late answer for settings changed since', async () => {
            /** @type {(value: unknown) => void} */
            let answerOld = () => {};
            global.fetch = vi.fn((url, opts) => {
                const body = JSON.parse(opts.body);
                if (body.host === 'imap.unite.be') {
                    return new Promise((resolve) => {
                        answerOld = () => resolve({
                            ok: true,
                            status: 200,
                            json: () => Promise.resolve({ success: true, folders: ['Ancien'], sent_folder: 'Vieux' }),
                        });
                    });
                }
                return jsonResponse({ success: true, folders: ['Nouveau'], sent_folder: null });
            });
            await boot();
            fillConnection();
            openFolders();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));

            type('mailbox-host', 'imap.nouveau.be');
            await vi.waitFor(() => expect(document.querySelector('.folder-checkbox')?.value).toBe('Nouveau'));

            answerOld();
            await new Promise((resolve) => setTimeout(resolve, 0));
            expect([...document.querySelectorAll('.folder-checkbox')].map((cb) => cb.value)).toEqual(['Nouveau']);
            expect($('mailbox-sent-found').hidden).toBe(true);
        });

        it('keeps a folder typed while the server was answering', async () => {
            /** @type {(value: unknown) => void} */
            let answer = () => {};
            global.fetch = vi.fn(() => new Promise((resolve) => {
                answer = () => resolve({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve({ success: true, folders: ['Factures'], sent_folder: null }),
                });
            }));
            await boot();
            fillConnection();
            openFolders();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));

            $('mailbox-folders').value = 'Locations';
            answer();

            await vi.waitFor(() => expect(document.querySelectorAll('.folder-checkbox').length).toBe(2));
            const boxes = [...document.querySelectorAll('.folder-checkbox')];
            expect(boxes.map((cb) => [cb.value, cb.checked])).toEqual([['Factures', false], ['Locations', true]]);
            expect($('mailbox-folders').value).toBe('Locations');
        });

        it('lists the folders of a section rendered already open, which fires no toggle', async () => {
            global.fetch = mockFetch({ 'test-connexion': { success: true, folders: ['Factures'], sent_folder: null } });
            fillConnection();
            $('mailbox-id').value = '42';
            $('mailbox-password').value = '';
            $('mailbox-folders').value = 'Factures';
            $('mailbox-folders-details').open = true;
            // The section's own toggle is spent before the script listens,
            // as when the page parses an already open <details>.
            await new Promise((resolve) => setTimeout(resolve, 0));
            await boot();

            await vi.waitFor(() => expect(document.querySelectorAll('.folder-checkbox').length).toBe(1));
            expect(document.querySelector('.folder-checkbox').checked).toBe(true);
        });

        it('lists the folders of a saved box without retyping its password', async () => {
            await boot();
            fillConnection();
            $('mailbox-id').value = '42';
            $('mailbox-password').value = '';
            openFolders();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().body.mailbox_id).toBe(42);
        });
    });
});
