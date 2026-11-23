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
//   - « Dossiers lus » shows only once the connection the form holds has
//     answered (#1343): the inbox ticked for good and never posted, the
//     server's other folders as boxes named folders[], the box's stored
//     choice kept, the folders it names that the server lacks said, and
//     the sent mail found from the server's mark or chosen in its list;
//     a failure says why and shows nothing; a change of server, address,
//     password or usage hides it until the new settings answer, and a
//     late answer for old settings is dropped;
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

        <button type="button" id="mailbox-folders-check" hidden>Vérifier la connexion et choisir les dossiers</button>
        <output id="mailbox-folders-status" aria-live="polite"></output>
        <fieldset id="mailbox-folders" hidden data-chosen="[]" data-sent="">
            <div id="mailbox-folders-list"></div>
            <p id="mailbox-folders-missing" hidden></p>
            <select id="mailbox-sent-folder" name="sent_folder" disabled></select>
            <div id="mailbox-sent-help"></div>
            <input type="hidden" name="folders_verified" id="mailbox-folders-verified" value="1" disabled>
        </fieldset>
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

function checkConnection() {
    $('mailbox-folders-check').click();
}

function boxes() {
    return [...document.querySelectorAll('.folder-checkbox')];
}

/** What the form would post about folders, as FormData builds it. */
function posted() {
    const data = new FormData($('mailbox-form'));
    return { folders: data.getAll('folders[]'), sent: data.get('sent_folder'), verified: data.get('folders_verified') };
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

        it('decides field by field: a port and an encryption chosen with the server empty stay', async () => {
            global.fetch = mockFetch({ fournisseur: GMAIL });
            await boot();
            $('mailbox-port').value = '143';
            $('mailbox-encryption').value = 'starttls';
            type('mailbox-username', 'contact.unite@gmail.com');

            await vi.waitFor(() => expect($('mailbox-host').value).toBe('imap.gmail.com'));
            expect($('mailbox-port').value).toBe('143');
            expect($('mailbox-encryption').value).toBe('starttls');

            // An unknown address then clears only the server the script wrote.
            global.fetch = mockFetch({ fournisseur: { recognized: false, provider: null } });
            type('mailbox-username', 'unite@exemple-hebergeur.be');
            await vi.waitFor(() => expect($('mailbox-host').value).toBe(''));
            expect($('mailbox-port').value).toBe('143');
            expect($('mailbox-encryption').value).toBe('starttls');
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

        it('asks once on load about an address the page already holds', async () => {
            global.fetch = mockFetch({ fournisseur: GMAIL });
            $('mailbox-username').value = 'contact.unite@gmail.com';
            $('mailbox-host').value = 'imap.mon-serveur.be';
            await boot();

            await vi.waitFor(() => expect($('mailbox-provider-known').hidden).toBe(false));
            expect(lastRequest().url).toBe('/config/courrier-entrant/fournisseur');
            expect($('mailbox-host').value).toBe('imap.mon-serveur.be');
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

        const LWS = {
            success: true,
            message: 'Connexion réussie. 4 dossier(s) visible(s).',
            folders: ['INBOX.Sent', 'INBOX.Trash', 'Factures'],
            sent_folder: null,
            sent_candidates: ['INBOX.Sent', 'INBOX.Trash', 'Factures'],
        };

        it('stays hidden, posting nothing, until the connection has answered', async () => {
            await boot();
            expect($('mailbox-folders').hidden).toBe(true);
            expect($('mailbox-folders-check').hidden).toBe(false);
            expect(posted()).toEqual({ folders: [], sent: null, verified: null });

            checkConnection();
            expect($('mailbox-folders-status').textContent).toContain('Renseignez');
            expect(fetch).not.toHaveBeenCalled();
        });

        it('shows the inbox ticked for good and the server folders unticked, without attributes', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            await boot();
            fillConnection();
            checkConnection();

            await vi.waitFor(() => expect($('mailbox-folders').hidden).toBe(false));
            const { url, body } = lastRequest();
            expect(url).toBe('/config/courrier-entrant/test-connexion');
            expect(url).not.toContain('secret');
            expect(body.password).toBe('secret');

            expect(boxes().map((cb) => [cb.value, cb.checked, cb.disabled])).toEqual([
                ['INBOX', true, true],
                ['INBOX.Sent', false, false],
                ['INBOX.Trash', false, false],
                ['Factures', false, false],
            ]);
            expect($('mailbox-folders-status').textContent).toContain('Connexion réussie');
            // The inbox is read whatever is posted: it is never posted.
            expect(posted()).toEqual({ folders: [], sent: '', verified: '1' });

            boxes()[3].checked = true;
            boxes()[3].dispatchEvent(new Event('change', { bubbles: true }));
            expect(posted().folders).toEqual(['Factures']);
        });

        it('offers the server folders as the sent mail when the server marks none', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            await boot();
            fillConnection();
            checkConnection();
            await vi.waitFor(() => expect($('mailbox-sent-folder').disabled).toBe(false));

            const options = [...$('mailbox-sent-folder').options].map((o) => [o.value, o.textContent]);
            expect(options).toEqual([
                ['', 'Aucun'],
                ['INBOX.Sent', 'INBOX.Sent'],
                ['INBOX.Trash', 'INBOX.Trash'],
                ['Factures', 'Factures'],
            ]);
            expect($('mailbox-sent-help').textContent).toContain("n'indique pas");

            $('mailbox-sent-folder').value = 'INBOX.Sent';
            $('mailbox-sent-folder').dispatchEvent(new Event('change', { bubbles: true }));
            expect(posted().sent).toBe('INBOX.Sent');
        });

        it('says which folder the server marks as sent, and keeps the stored choice', async () => {
            global.fetch = mockFetch({
                'test-connexion': {
                    success: true,
                    folders: ['Factures', 'Locations'],
                    sent_folder: '[Gmail]/Messages envoyés',
                    sent_candidates: ['Factures', 'Locations', '[Gmail]/Messages envoyés'],
                },
            });
            $('mailbox-folders').dataset.chosen = JSON.stringify(['Locations']);
            await boot();
            fillConnection();
            checkConnection();

            await vi.waitFor(() => expect($('mailbox-folders').hidden).toBe(false));
            expect(boxes().map((cb) => [cb.value, cb.checked])).toEqual([
                ['INBOX', true], ['Factures', false], ['Locations', true],
            ]);
            expect($('mailbox-sent-folder').options[0].textContent)
                .toBe('Trouvé automatiquement : [Gmail]/Messages envoyés');
            expect($('mailbox-sent-folder').value).toBe('');
            expect(posted().folders).toEqual(['Locations']);
        });

        it('keeps a stored sent folder selected when the server still has it', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            $('mailbox-folders').dataset.sent = 'INBOX.Sent';
            await boot();
            fillConnection();
            checkConnection();

            await vi.waitFor(() => expect($('mailbox-sent-folder').disabled).toBe(false));
            expect(posted().sent).toBe('INBOX.Sent');
        });

        it('says which stored folders the server no longer has, rather than dropping them unseen', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            $('mailbox-folders').dataset.chosen = JSON.stringify(['Factures', 'Archives 2019']);
            await boot();
            fillConnection();
            checkConnection();

            await vi.waitFor(() => expect($('mailbox-folders').hidden).toBe(false));
            expect($('mailbox-folders-missing').hidden).toBe(false);
            expect($('mailbox-folders-missing').textContent).toContain('Archives 2019');
            expect(boxes().map((cb) => cb.value)).not.toContain('Archives 2019');
            expect(posted().folders).toEqual(['Factures']);
        });

        it('says when the stored sent folder is gone, rather than replacing it unseen', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            $('mailbox-folders').dataset.sent = 'INBOX.Ancien';
            await boot();
            fillConnection();
            checkConnection();

            await vi.waitFor(() => expect($('mailbox-sent-folder').disabled).toBe(false));
            expect($('mailbox-folders-missing').hidden).toBe(false);
            expect($('mailbox-folders-missing').textContent).toContain('INBOX.Ancien');
            expect(posted().sent).toBe('');
        });

        it('says nothing about the sent folder when none was chosen', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            await boot();
            fillConnection();
            checkConnection();

            await vi.waitFor(() => expect($('mailbox-sent-folder').disabled).toBe(false));
            expect($('mailbox-folders-missing').hidden).toBe(true);
        });

        it('says which module the box is for, since a seed box is offered its junk folder', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            await boot();
            fillConnection();
            $('mailbox-dedicated-to').innerHTML = '<option value="core_mail_seed" selected>Témoins</option>';
            $('mailbox-purpose-dedicated').checked = true;
            checkConnection();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastRequest().body.dedicated_to).toBe('core_mail_seed');
        });

        it('says why on failure and shows nothing', async () => {
            global.fetch = mockFetch({ 'test-connexion': { success: false, error: 'Connexion refusée.' } });
            await boot();
            fillConnection();
            checkConnection();

            await vi.waitFor(() => expect($('mailbox-folders-status').textContent).toBe('Connexion refusée.'));
            expect($('mailbox-folders').hidden).toBe(true);
            expect(posted().verified).toBeNull();
        });

        it('hides the listing for other settings, and verifies them again', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            await boot();
            fillConnection();
            checkConnection();
            await vi.waitFor(() => expect($('mailbox-folders').hidden).toBe(false));

            // The same settings again ask nothing.
            checkConnection();
            expect(fetch).toHaveBeenCalledTimes(1);

            let answer = () => {};
            global.fetch = vi.fn(() => new Promise((resolve) => {
                answer = () => resolve({
                    ok: true, status: 200,
                    json: () => Promise.resolve({
                        success: true, folders: ['Archives'], sent_folder: null, sent_candidates: ['Archives'],
                    }),
                });
            }));
            type('mailbox-host', 'imap.ailleurs.be');

            // Hidden at once, nothing of the old listing posted meanwhile.
            expect($('mailbox-folders').hidden).toBe(true);
            expect(posted()).toEqual({ folders: [], sent: null, verified: null });

            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
            answer();
            await vi.waitFor(() => expect(boxes().map((cb) => cb.value)).toEqual(['INBOX', 'Archives']));
            expect(lastRequest().body.host).toBe('imap.ailleurs.be');
        });

        it('takes another password for another connection', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            await boot();
            fillConnection();
            checkConnection();
            await vi.waitFor(() => expect($('mailbox-folders').hidden).toBe(false));

            type('mailbox-password', 'autre-secret');

            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
            expect(lastRequest().body.password).toBe('autre-secret');
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
                            json: () => Promise.resolve({
                                success: true, folders: ['Ancien'], sent_folder: 'Vieux', sent_candidates: ['Ancien'],
                            }),
                        });
                    });
                }
                return jsonResponse({
                    success: true, folders: ['Nouveau'], sent_folder: null, sent_candidates: ['Nouveau'],
                });
            });
            await boot();
            fillConnection();
            checkConnection();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));

            type('mailbox-host', 'imap.nouveau.be');
            await vi.waitFor(() => expect(boxes().map((cb) => cb.value)).toEqual(['INBOX', 'Nouveau']));

            answerOld();
            await new Promise((resolve) => setTimeout(resolve, 0));
            expect(boxes().map((cb) => cb.value)).toEqual(['INBOX', 'Nouveau']);
            expect($('mailbox-sent-folder').options[0].textContent).toBe('Aucun');
        });

        it('verifies once the missing password is typed', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            await boot();
            fillConnection();
            $('mailbox-password').value = '';
            type('mailbox-host', 'imap.unite.be');
            await new Promise((resolve) => setTimeout(resolve, 0));
            expect(fetch).not.toHaveBeenCalled();

            type('mailbox-password', 'secret');

            await vi.waitFor(() => expect($('mailbox-folders').hidden).toBe(false));
        });

        it('tries again after a failed verification once a setting is corrected', async () => {
            let answer = { success: false, error: 'Identifiants refusés.' };
            global.fetch = vi.fn(() => jsonResponse(answer));
            await boot();
            fillConnection();
            checkConnection();
            await vi.waitFor(() => expect($('mailbox-folders-status').textContent).toContain('Identifiants refusés'));

            answer = LWS;
            type('mailbox-host', 'imap.autre.be');

            await vi.waitFor(() => expect($('mailbox-folders').hidden).toBe(false));
        });

        it('verifies a saved box on load, with its stored password', async () => {
            global.fetch = mockFetch({ 'test-connexion': LWS });
            fillConnection();
            $('mailbox-id').value = '42';
            $('mailbox-password').value = '';
            $('mailbox-folders').dataset.chosen = JSON.stringify(['Factures']);
            await boot();

            await vi.waitFor(() => expect($('mailbox-folders').hidden).toBe(false));
            expect(lastRequest().body.mailbox_id).toBe(42);
            expect(posted().folders).toEqual(['Factures']);
        });
    });
});
