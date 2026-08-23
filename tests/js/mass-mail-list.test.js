// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and the site-wide dialogs
// (window.ScoutMagicConfirm / window.ScoutMagicToast, loaded by
// base.html.twig in production) are stubbed, so the answer to the
// start-sending confirmation is something the test chooses rather than
// something a real modal has to be clicked for.
//
// Exercises the REAL public/assets/js/mass-mail-list.js (imported below,
// never reimplemented) on top of the real api.js envelope, with the
// server-side payload handed over as window.massMailListData exactly as
// _compose_dialog.html.twig's inline nonce-tagged script does.
//
// SCOPE. Most of this file is dialog glue — a few hundred lines of "show
// this zone, hide that one" that AGENTS.md § Tests explicitly says is often
// not worth isolating. This suite deliberately covers the parts that are
// NOT glue and that a wrong answer makes expensive:
//   - the start-sending confirmation, whose whole point is that a refusal
//     reaches no endpoint (an email cannot be un-sent);
//   - isListAllowed()'s permission rules, mirrored from the server;
//   - which controls a given status locks, since that lock is what stops a
//     sent email from being edited underneath its own recipients.
// The rest is left to tests/e2e/specs/mass-mail-merge.spec.js and the PHP
// integration tests.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const START_SENDING_MESSAGE =
    'Lancer l\'envoi ? La liste des destinataires sera figée et l\'envoi ne pourra plus être annulé.';

function jsonResponse(data) {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
}

async function settle() {
    // A macrotask hop drains the whole microtask queue first — the
    // ScoutMagicApi envelope plus the awaited confirmation add promise
    // layers a fixed number of Promise.resolve() awaits kept undercounting.
    await new Promise((resolve) => setTimeout(resolve, 0));
}

/** The compose dialog's markup, trimmed to the elements the script reaches for. */
function buildDom() {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <button id="mm-new-btn">Nouvel email</button>
        <button class="mm-open-btn" data-id="7">Ouvrir</button>
        <div id="mm-modal">
            <h2 id="mm-modal-title"></h2>
            <span id="mm-status-badge" class="badge"></span>
            <div id="mm-error" class="d-none"></div>

            <select id="mm-section"></select>
            <select id="mm-list"></select>

            <div id="mm-scout-year-zone">
                <div id="mm-scout-year-group"></div>
                <p id="mm-previous-year-note"></p>
            </div>

            <div id="mm-merge-list-note" class="d-none"></div>
            <div id="mm-merge-zone" class="d-none">
                <div id="mm-merge-upload-state">
                    <input type="file" id="mm-merge-file">
                    <button id="mm-merge-import-btn">Importer</button>
                </div>
                <div id="mm-merge-info-state" class="d-none">
                    <span id="mm-merge-filename"></span>
                    <span id="mm-merge-meta"></span>
                    <div id="mm-merge-columns"></div>
                    <button id="mm-merge-replace-btn">Remplacer</button>
                </div>
                <div id="mm-merge-warnings" class="d-none"></div>
            </div>

            <input id="mm-subject">
            <div id="mm-body-toolbar">
                <button data-command="bold">B</button>
                <div id="mm-variable-dropdown" class="d-none"><ul id="mm-variable-menu"></ul></div>
            </div>
            <div id="mm-body-content" contenteditable="true"></div>

            <div id="mm-attachments-list"></div>
            <div id="mm-attachment-upload-zone" class="d-none">
                <input type="file" id="mm-attachment-file">
                <button id="mm-attachment-upload-btn">Joindre</button>
            </div>
            <p id="mm-attachment-hint" class="d-none"></p>

            <div id="mm-merge-preview-zone" class="d-none">
                <span id="mm-merge-preview-position"></span>
                <span id="mm-merge-preview-recipient"></span>
                <span id="mm-merge-preview-subject"></span>
                <div id="mm-merge-preview-body"></div>
                <div id="mm-merge-preview-warnings" class="d-none"></div>
                <button id="mm-merge-prev-btn">Préc.</button>
                <button id="mm-merge-next-btn">Suiv.</button>
            </div>

            <div id="mm-test-send-zone" class="d-none">
                <input id="mm-test-send-email">
                <button id="mm-test-send-btn">Envoyer un test</button>
                <p id="mm-test-send-merge-note" class="d-none"></p>
            </div>

            <div id="mm-progress-zone" class="d-none">
                <div id="mm-progress-sent"></div>
                <div id="mm-progress-error"></div>
                <div id="mm-progress-pending"></div>
                <span id="mm-progress-text"></span>
                <a id="mm-tracking-link" href="#">Suivi</a>
            </div>

            <button id="mm-save-btn">Enregistrer</button>
            <button id="mm-to-test-btn">Passer en test</button>
            <button id="mm-to-draft-btn">Repasser en brouillon</button>
            <button id="mm-start-sending-btn">Lancer l'envoi</button>
        </div>
    `;
}

/**
 * The server payload the page's inline script sets.
 *
 * @param {{unrestricted?: boolean}} [overrides]
 */
function listData(overrides = {}) {
    return Object.assign({
        unrestricted: true,
        forcedSectionId: null,
        userSectionIds: [2],
        currentUserEmail: 'chef@example.org',
        previousYearCutoff: '10-15',
        sections: [{ id: 2, name: 'Louveteaux' }, { id: 3, name: 'Éclaireurs' }],
        defaultLists: [
            { list_type: 'default_chiefs', list_section_id: null, label: 'Les chefs', description: 'Tous les chefs' },
            { list_type: 'default_section', list_section_id: 2, label: 'Louveteaux', description: 'Section' },
            { list_type: 'default_section', list_section_id: 3, label: 'Éclaireurs', description: 'Section' },
            { list_type: 'default_all', list_section_id: null, label: 'Membres actifs', description: 'Tous' },
        ],
        customLists: [{ id: 9, name: 'Anciens', description: 'Liste maison' }],
        scoutYears: {
            previous: { id: 1, label: '2024-2025', available: true },
            current: { id: 2, label: '2025-2026', available: true },
            next: { id: 3, label: '2026-2027', available: false },
        },
    }, overrides);
}

/** One email as GET /mass-mail/{id} returns it. */
function email(overrides = {}) {
    return {
        success: true,
        email: Object.assign({
            id: 7,
            subject: 'Camp d\'été',
            section_id: 2,
            list_type: 'default_section',
            list_id: null,
            list_section_id: 2,
            scout_year_ids: [2],
            body_html: '<p>Bonjour.</p>',
            status: 'test',
            audience_id: null,
        }, overrides),
        attachments: [],
        counts: { total: 0, sent: 0, error: 0, pending: 0 },
    };
}

/**
 * Boots the page, then opens email #7 so mmCurrentId is set — the status
 * actions all address the email currently in the dialog.
 *
 * @param {object} [data] the window.massMailListData payload
 * @param {object} [opened] the email GET response
 */
async function bootAndOpen(data = listData(), opened = email()) {
    buildDom();
    window.massMailListData = data;
    global.fetch = vi.fn(() => jsonResponse(opened));
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/mass-mail-list.js');

    document.querySelector('.mm-open-btn').dispatchEvent(new Event('click'));
    await settle();
    fetch.mockClear();
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

describe('mass-mail-list.js: starting the send', () => {
    it('asks the shared confirmation — never the native box — with the French message and « Envoyer »', async () => {
        const nativeConfirm = vi.fn(() => true);
        window.confirm = nativeConfirm;
        await bootAndOpen();

        document.getElementById('mm-start-sending-btn').click();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: START_SENDING_MESSAGE,
            confirmLabel: 'Envoyer',
            // Irreversible, but it destroys nothing: sending is the point of
            // the screen, so the primary button, not the danger one.
            variant: 'primary',
        });
        expect(nativeConfirm).not.toHaveBeenCalled();
    });

    it('posts nothing when the confirmation resolves false — an email cannot be un-sent', async () => {
        await bootAndOpen();
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('posts start_sending for the open email when it resolves true', async () => {
        await bootAndOpen();

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/mass-mail/7/status');
        expect(JSON.parse(fetch.mock.calls[0][1].body))
            .toEqual({ action: 'start_sending', _csrf_token: 'tok' });
    });

    it('shows the server refusal in the dialog\'s own error box', async () => {
        await bootAndOpen();
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Aucun destinataire.' }));

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        const box = document.getElementById('mm-error');
        expect(box.textContent).toBe('Aucun destinataire.');
        expect(box.classList.contains('d-none')).toBe(false);
    });
});

describe('mass-mail-list.js: the status changes that ask nothing', () => {
    it('moves a draft to test without any confirmation at all', async () => {
        await bootAndOpen(listData(), email({ status: 'draft' }));

        document.getElementById('mm-to-test-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
        expect(JSON.parse(fetch.mock.calls[0][1].body))
            .toEqual({ action: 'to_test', _csrf_token: 'tok' });
    });

    it('moves a test email back to draft without any confirmation — nothing is lost', async () => {
        await bootAndOpen();

        document.getElementById('mm-to-draft-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask).not.toHaveBeenCalled();
        expect(JSON.parse(fetch.mock.calls[0][1].body))
            .toEqual({ action: 'to_draft', _csrf_token: 'tok' });
    });
});

describe('mass-mail-list.js: which lists a restricted account may pick', () => {
    /** @param {string} label @returns {HTMLOptionElement} */
    function option(label) {
        return /** @type {HTMLOptionElement} */ ([...document.querySelectorAll('#mm-list option')]
            .find((o) => o.textContent === label));
    }

    it('leaves every list open to a chef d\'unité', async () => {
        await bootAndOpen(listData(), email({ status: 'draft' }));
        expect(option('Membres actifs').disabled).toBe(false);
        expect(option('Anciens').disabled).toBe(false);
        // An unrestricted account picks the sender section freely — as long
        // as the email is still a draft.
        expect(/** @type {HTMLSelectElement} */ (document.getElementById('mm-section')).disabled).toBe(false);
    });

    it('locks a restricted chief to the chiefs list, their own section, and publipostage', async () => {
        await bootAndOpen(listData({ unrestricted: false, forcedSectionId: 2 }), email({ status: 'draft' }));

        expect(option('Les chefs').disabled).toBe(false);
        expect(option('Louveteaux').disabled).toBe(false);
        expect(option('Publipostage — fichier Excel').disabled).toBe(false);
        // Another section's list, "Membres actifs" and every custom list.
        expect(option('Éclaireurs').disabled).toBe(true);
        expect(option('Membres actifs').disabled).toBe(true);
        expect(option('Anciens').disabled).toBe(true);
        // The sender section is not a free choice either.
        expect(/** @type {HTMLSelectElement} */ (document.getElementById('mm-section')).disabled).toBe(true);
    });
});

describe('mass-mail-list.js: what each status locks', () => {
    /** @param {string} id */
    function hidden(id) {
        return document.getElementById(id).classList.contains('d-none');
    }

    it('leaves a draft fully editable, with no test-send or progress zone', async () => {
        await bootAndOpen(listData(), email({ status: 'draft' }));

        expect(/** @type {HTMLInputElement} */ (document.getElementById('mm-subject')).disabled).toBe(false);
        expect(document.getElementById('mm-body-content').getAttribute('contenteditable')).toBe('true');
        expect(hidden('mm-save-btn')).toBe(false);
        expect(hidden('mm-test-send-zone')).toBe(true);
        expect(hidden('mm-progress-zone')).toBe(true);
    });

    it('freezes the fields in test mode and offers the test send', async () => {
        await bootAndOpen();

        expect(/** @type {HTMLInputElement} */ (document.getElementById('mm-subject')).disabled).toBe(true);
        expect(document.getElementById('mm-body-content').getAttribute('contenteditable')).toBe('false');
        expect(hidden('mm-save-btn')).toBe(true);
        expect(hidden('mm-test-send-zone')).toBe(false);
        expect(hidden('mm-start-sending-btn')).toBe(false);
    });

    it('shows only the progress zone and the tracking link once sending has started', async () => {
        await bootAndOpen(listData(), email({ status: 'sending' }));

        expect(hidden('mm-progress-zone')).toBe(false);
        expect(hidden('mm-start-sending-btn')).toBe(true);
        expect(hidden('mm-test-send-zone')).toBe(true);
        expect(/** @type {HTMLAnchorElement} */ (document.getElementById('mm-tracking-link')).href)
            .toContain('/mass-mail/7/tracking');
        expect(document.getElementById('mm-status-badge').className).toContain('text-bg-warning');
    });

    it('labels a sent email in French with the matching badge tone', async () => {
        await bootAndOpen(listData(), email({ status: 'sent' }));

        expect(document.getElementById('mm-status-badge').textContent).toBe('Envoyé');
        expect(document.getElementById('mm-status-badge').className).toContain('text-bg-success');
    });

    it('toasts rather than silently doing nothing when the email cannot be loaded', async () => {
        buildDom();
        window.massMailListData = listData();
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Email introuvable.' }));
        vi.resetModules();
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/mass-mail-list.js');

        document.querySelector('.mm-open-btn').dispatchEvent(new Event('click'));
        await settle();

        expect(window.ScoutMagicToast.show)
            .toHaveBeenCalledWith('Email introuvable.', { variant: 'error' });
    });
});

describe('mass-mail-list.js: the scout-year block', () => {
    it('checks the year the email actually targets, and disables a year with no Desk import', async () => {
        await bootAndOpen(listData(), email({ scout_year_ids: [1, 2] }));

        const boxes = /** @type {NodeListOf<HTMLInputElement>} */ (
            document.querySelectorAll('#mm-scout-year-group .mm-year-checkbox')
        );
        expect([...boxes].filter((b) => b.checked).map((b) => b.value)).toEqual(['1', '2']);
        // "next" is unavailable in the fixture: a permanent lock, not the
        // temporary read-only one a non-draft status applies.
        expect(boxes[2].disabled).toBe(true);
    });

    it('spells out the previous-year cutoff in French, day before month', async () => {
        await bootAndOpen();
        expect(document.getElementById('mm-previous-year-note').textContent)
            .toContain('généralement autour du 15/10');
    });
});

describe('mass-mail-list.js: saving a draft', () => {
    it('refuses to save with no scout year selected, without reaching the server', async () => {
        await bootAndOpen(listData(), email({ status: 'draft', scout_year_ids: [] }));

        document.getElementById('mm-save-btn').click();
        await settle();

        expect(document.getElementById('mm-error').textContent)
            .toBe('Au moins une année scoute doit être sélectionnée.');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('PATCHes an existing draft with the subject, body and audience selection', async () => {
        await bootAndOpen(listData(), email({ status: 'draft' }));
        /** @type {HTMLInputElement} */ (document.getElementById('mm-subject')).value = 'Camp d\'hiver';

        document.getElementById('mm-save-btn').click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/mass-mail/7');
        expect(fetch.mock.calls[0][1].method).toBe('PATCH');
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({
            subject: 'Camp d\'hiver',
            body_html: '<p>Bonjour.</p>',
            list_type: 'default_section',
            list_section_id: 2,
            audience_id: null,
            scout_year_ids: [2],
        });
    });
});
