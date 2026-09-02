// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and the site-wide dialog
// (window.ScoutMagicConfirm, loaded by base.html.twig in production) is
// stubbed, so the answer to the start-sending confirmation is something
// the test chooses rather than something a real modal has to be clicked
// for.
//
// Exercises the REAL public/assets/js/mass-mail-compose.js (imported
// below, never reimplemented) on top of the real api.js envelope, with
// the page's payload handed over in the JSON island exactly as
// modules/mass_mail/views/compose.html.twig writes it.
//
// SCOPE. The composition page is mostly ordinary forms — saving, adding an
// attachment, changing status and sending a test are POSTs that redirect,
// and they work with this script absent. What is covered here is what the
// script alone decides, and what a wrong answer makes expensive:
//   - the start-sending confirmation, whose whole point is that a refusal
//     reaches no endpoint (an email cannot be un-sent);
//   - the merge preview's offset, which is what row the test send carries:
//     showing row 2 and sending row 1 would be a wrong personalisation
//     nobody notices until it is in a mailbox;
//   - variable insertion, which exists so a column name is never mistyped;
//   - the list type reshaping the form, since the scout-year block and the
//     mail-merge zone are mutually exclusive by rule, not by luck.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Installs the page's server data the way the template does — a
 * `<script type="application/json">` island. ScoutMagicApi.pageData()
 * reads it.
 */
function installIsland(id, data) {
    document.getElementById(id)?.remove();
    const el = document.createElement('script');
    el.type = 'application/json';
    el.id = id;
    el.textContent = JSON.stringify(data);
    document.body.appendChild(el);
}

function jsonResponse(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
}

async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

const START_SENDING_MESSAGE =
    'Lancer l\'envoi ? La liste des destinataires sera figée et l\'envoi ne pourra plus être annulé.';

/** The composition page's markup, trimmed to what the script reaches for. */
function buildDom({ status = 'draft', listType = 'default_section', merge = false } = {}) {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <form id="mm-compose-form">
            <select id="mm-list">
                <option value="default_section:2" data-list-type="default_section"${listType === 'default_section' ? ' selected' : ''}>Section</option>
                <option value="external:" data-list-type="external"${listType === 'external' ? ' selected' : ''}>Inscriptions</option>
                <option value="mail_merge:" data-list-type="mail_merge"${listType === 'mail_merge' ? ' selected' : ''}>Publipostage</option>
            </select>
            <p id="mm-merge-list-note" class="d-none"></p>
            <p id="mm-external-list-note" class="d-none"></p>

            <div id="mm-merge-zone" class="d-none">
                <input type="hidden" id="mm-audience-id" name="audience_id" value="">
                <div id="mm-merge-upload-state">
                    <input type="file" id="mm-merge-file">
                    <button type="button" id="mm-merge-import-btn">Importer</button>
                </div>
                <div id="mm-merge-info-state" class="d-none">
                    <div id="mm-merge-filename"></div>
                    <div id="mm-merge-meta"></div>
                    <button type="button" id="mm-merge-replace-btn">Remplacer</button>
                    <div id="mm-merge-columns"></div>
                </div>
                <div id="mm-merge-warnings" class="d-none"></div>
                <div id="mm-merge-error" class="d-none"></div>
            </div>

            <div id="mm-scout-year-zone">
                <div id="mm-scout-year-group">
                    <input type="checkbox" class="mm-year-checkbox" id="mm-year-current" value="2" data-warning="" checked>
                    <input type="checkbox" class="mm-year-checkbox" id="mm-year-next" value="3" data-warning="Année à venir : la liste s'appuie sur Desk.">
                </div>
                <div id="mm-future-year-warning" class="d-none"></div>
            </div>

            <input type="text" id="mm-subject" name="subject" value="">
            <div class="rich-text-form-field">
                <div id="mm-variable-dropdown" class="dropdown">
                    <ul id="mm-variable-menu">
                        <li><button type="button" class="mm-variable-item" data-column="Prénom">Prénom</button></li>
                    </ul>
                </div>
                <div id="mm-body-content" contenteditable="true"></div>
            </div>
        </form>

        ${merge ? `
        <div id="mm-merge-preview-zone">
            <button type="button" id="mm-merge-prev-btn"></button>
            <span id="mm-merge-preview-position"></span>
            <button type="button" id="mm-merge-next-btn"></button>
            <p id="mm-merge-preview-recipient"></p>
            <div id="mm-merge-preview-warnings" class="d-none"></div>
            <strong id="mm-merge-preview-subject"></strong>
            <div id="mm-merge-preview-body"></div>
        </div>` : ''}

        <form id="mm-test-send-form">
            <input type="hidden" id="mm-merge-offset" name="merge_offset" value="0">
            <input type="email" id="mm-test-send-email" name="to" value="">
        </form>

        <form id="mm-start-sending-form">
            <input type="hidden" name="action" value="start_sending">
            <button type="submit">Lancer l'envoi</button>
        </form>
    `;
    installIsland('mass-mail-compose-data', { emailId: 7, status });
}

/**
 * @param {{status?: string, listType?: string, merge?: boolean, fetch?: Function}} [options]
 */
async function boot(options = {}) {
    buildDom(options);
    global.fetch = vi.fn(options.fetch || (() => jsonResponse({ success: true })));
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/mass-mail-compose.js');
    await settle();
}

/** One merge-preview payload as the server returns it. */
function preview(overrides = {}) {
    return {
        success: true,
        preview: Object.assign({
            offset: 0,
            total: 2,
            row_index: 2,
            recipient_label: 'kaa@example.test',
            subject: 'Camp Kaa',
            body_html: '<p>Cher Kaa</p>',
            unknown_tokens: [],
            missing_values: [],
        }, overrides),
    };
}

beforeEach(() => {
    vi.resetModules();
    vi.restoreAllMocks();
    global.fetch = vi.fn(() => jsonResponse({ success: true }));
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    window.ScoutMagicToast = { show: vi.fn() };
    // jsdom does not implement form submission; the script calls it
    // directly once the confirmation is answered.
    HTMLFormElement.prototype.submit = vi.fn();
    document.execCommand = vi.fn(() => true);
});

describe('mass-mail-compose.js: starting the send', () => {
    it('asks the shared confirmation with the live recipient count, never the native box', async () => {
        const nativeConfirm = vi.fn(() => true);
        window.confirm = nativeConfirm;
        await boot({ status: 'test' });
        global.fetch = vi.fn(() => jsonResponse({ success: true, count: 42, kind: 'members' }));

        document.getElementById('mm-start-sending-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/mass-mail/7/recipient-count');
        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: 'Cet email partira à 42 personnes. ' + START_SENDING_MESSAGE,
            confirmLabel: 'Envoyer',
            variant: 'primary',
        });
        expect(nativeConfirm).not.toHaveBeenCalled();
    });

    it('counts a mail merge in file rows, not in people', async () => {
        await boot({ status: 'test', listType: 'mail_merge' });
        global.fetch = vi.fn(() => jsonResponse({ success: true, count: 1, kind: 'rows' }));

        document.getElementById('mm-start-sending-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await settle();

        expect(window.ScoutMagicConfirm.ask.mock.calls[0][0].message)
            .toBe('Cet email partira à 1 ligne du fichier. ' + START_SENDING_MESSAGE);
    });

    it('still asks, without a number, when the count cannot be had', async () => {
        await boot({ status: 'test' });
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Email introuvable.' }));

        document.getElementById('mm-start-sending-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await settle();

        expect(window.ScoutMagicConfirm.ask.mock.calls[0][0].message).toBe(START_SENDING_MESSAGE);
    });

    it('submits nothing when the manager says no', async () => {
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
        await boot({ status: 'test' });
        const form = document.getElementById('mm-start-sending-form');

        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await settle();

        expect(form.submit).not.toHaveBeenCalled();
        expect(form.dataset.confirmed).toBeUndefined();
    });

    it('submits the form once the manager agrees', async () => {
        await boot({ status: 'test' });
        const form = document.getElementById('mm-start-sending-form');

        form.dispatchEvent(new Event('submit', { cancelable: true }));
        await settle();

        expect(form.dataset.confirmed).toBe('1');
        expect(form.submit).toHaveBeenCalled();
    });
});

describe('mass-mail-compose.js: the list type reshapes the form', () => {
    it('swaps the scout-year block for the mail-merge zone', async () => {
        await boot();
        const select = document.getElementById('mm-list');
        expect(document.getElementById('mm-scout-year-zone').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('mm-merge-zone').classList.contains('d-none')).toBe(true);

        select.value = 'mail_merge:';
        select.dispatchEvent(new Event('change'));

        expect(document.getElementById('mm-scout-year-zone').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('mm-merge-zone').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('mm-merge-list-note').classList.contains('d-none')).toBe(false);
    });

    it('hides the scout years for a list whose year is fixed by its own module, and says why', async () => {
        await boot();
        const select = document.getElementById('mm-list');

        select.value = 'external:';
        select.dispatchEvent(new Event('change'));

        expect(document.getElementById('mm-scout-year-zone').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('mm-external-list-note').classList.contains('d-none')).toBe(false);
    });

    it('shows the server-written future-year warning only while such a year is ticked', async () => {
        await boot();
        const box = document.getElementById('mm-future-year-warning');
        expect(box.classList.contains('d-none')).toBe(true);

        const next = document.getElementById('mm-year-next');
        next.checked = true;
        next.dispatchEvent(new Event('change', { bubbles: true }));

        expect(box.classList.contains('d-none')).toBe(false);
        expect(box.textContent).toBe("Année à venir : la liste s'appuie sur Desk.");

        next.checked = false;
        next.dispatchEvent(new Event('change', { bubbles: true }));
        expect(box.classList.contains('d-none')).toBe(true);
    });
});

describe('mass-mail-compose.js: the per-recipient preview', () => {
    it('loads the first row as soon as a mail merge is in test mode', async () => {
        await boot({ status: 'test', listType: 'mail_merge', merge: true, fetch: () => jsonResponse(preview()) });

        expect(fetch.mock.calls[0][0]).toBe('/mass-mail/7/merge-preview?offset=0');
        expect(document.getElementById('mm-merge-preview-position').textContent).toBe('Ligne 1 / 2');
        expect(document.getElementById('mm-merge-preview-subject').textContent).toBe('Camp Kaa');
        expect(document.getElementById('mm-merge-preview-body').innerHTML).toBe('<p>Cher Kaa</p>');
    });

    /**
     * The test send carries the previewed row's values. A preview showing
     * row 2 while the hidden offset still said 0 would send somebody
     * else's personalisation, and nothing on screen would say so.
     */
    it('keeps the test send in step with the row on screen', async () => {
        await boot({
            status: 'test',
            listType: 'mail_merge',
            merge: true,
            fetch: (url) => jsonResponse(
                String(url).endsWith('offset=1') ? preview({ offset: 1, recipient_label: 'baloo@example.test' }) : preview()
            ),
        });
        expect(document.getElementById('mm-merge-offset').value).toBe('0');

        document.getElementById('mm-merge-next-btn').click();
        await settle();

        expect(document.getElementById('mm-merge-offset').value).toBe('1');
        expect(document.getElementById('mm-merge-preview-recipient').textContent)
            .toContain('baloo@example.test');
    });

    it('names an unknown variable rather than letting the braces reach a mailbox', async () => {
        await boot({
            status: 'test',
            listType: 'mail_merge',
            merge: true,
            fetch: () => jsonResponse(preview({ unknown_tokens: ['Prenom'], missing_values: ['Montant'] })),
        });

        const warnings = document.getElementById('mm-merge-preview-warnings');
        expect(warnings.classList.contains('d-none')).toBe(false);
        expect(warnings.textContent).toContain('{{Prenom}}');
        expect(warnings.textContent).toContain('Montant');
    });

    it('does not ask for a preview when the email is still a draft', async () => {
        await boot({ status: 'draft', listType: 'mail_merge', merge: true });

        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('mass-mail-compose.js: inserting a merge variable', () => {
    it('writes the token into the subject when the caret was there', async () => {
        await boot({ listType: 'mail_merge' });
        const subject = document.getElementById('mm-subject');
        subject.value = 'Camp ';
        subject.setSelectionRange(5, 5);
        subject.dispatchEvent(new Event('focus'));

        document.querySelector('.mm-variable-item').dispatchEvent(new Event('click', { bubbles: true }));

        expect(subject.value).toBe('Camp {{Prénom}}');
    });

    it('writes it into the body through execCommand when the caret was there', async () => {
        await boot({ listType: 'mail_merge' });
        document.getElementById('mm-body-content').dispatchEvent(new Event('focus'));

        document.querySelector('.mm-variable-item').dispatchEvent(new Event('click', { bubbles: true }));

        expect(document.execCommand).toHaveBeenCalledWith('insertText', false, '{{Prénom}}');
    });
});

describe('mass-mail-compose.js: importing the audience file', () => {
    it('attaches the imported audience to the form without leaving the page', async () => {
        await boot({ listType: 'mail_merge' });
        const file = document.getElementById('mm-merge-file');
        Object.defineProperty(file, 'files', {
            configurable: true,
            value: [new File(['x'], 'camp.xlsx')],
        });
        global.fetch = vi.fn((url) => jsonResponse(
            String(url).startsWith('/mass-mail/audiences/')
                ? { success: true, audience: { id: 12, columns: ['Prénom'], filename: 'camp.xlsx', sheet_name: 'F1', row_count: 2 }, sample: { 'Prénom': 'Kaa' } }
                : { success: true, audience: { id: 12, filename: 'camp.xlsx', sheet_name: 'F1', columns: ['Prénom'], row_count: 2 }, warnings: [] }
        ));

        document.getElementById('mm-merge-import-btn').click();
        await settle();

        expect(document.getElementById('mm-audience-id').value).toBe('12');
        expect(document.getElementById('mm-merge-filename').textContent).toBe('camp.xlsx');
        expect(document.getElementById('mm-merge-info-state').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('mm-variable-menu').textContent).toContain('Kaa');
    });

    /**
     * All-or-nothing: a refused file names every offending line at once,
     * and nothing is attached.
     */
    it('reports every refused line and attaches nothing', async () => {
        await boot({ listType: 'mail_merge' });
        const file = document.getElementById('mm-merge-file');
        Object.defineProperty(file, 'files', { configurable: true, value: [new File(['x'], 'bad.xlsx')] });
        global.fetch = vi.fn(() => jsonResponse({ success: false, errors: ['Ligne 2 : adresse invalide.', 'Ligne 3 : Tiers inconnu.'] }));

        document.getElementById('mm-merge-import-btn').click();
        await settle();

        const box = document.getElementById('mm-merge-error');
        expect(box.classList.contains('d-none')).toBe(false);
        expect(box.textContent).toContain('Ligne 2 : adresse invalide.');
        expect(box.textContent).toContain('Ligne 3 : Tiers inconnu.');
        expect(document.getElementById('mm-audience-id').value).toBe('');
    });

    it('escapes a column name that would otherwise open markup of its own', async () => {
        await boot({ listType: 'mail_merge' });
        const file = document.getElementById('mm-merge-file');
        Object.defineProperty(file, 'files', { configurable: true, value: [new File(['x'], 'camp.xlsx')] });
        global.fetch = vi.fn(() => jsonResponse({
            success: true,
            audience: { id: 12, filename: 'camp.xlsx', sheet_name: 'F1', columns: ['<img src=x onerror=alert(1)>'], row_count: 1 },
            warnings: [],
        }));

        document.getElementById('mm-merge-import-btn').click();
        await settle();

        expect(document.querySelector('#mm-merge-columns img')).toBeNull();
        expect(document.getElementById('mm-merge-columns').textContent).toContain('<img src=x onerror=alert(1)>');
    });
});
