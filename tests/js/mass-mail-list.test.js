// Isolated JavaScript unit test — jsdom DOM only. No PHP server, no MySQL,
// no network: fetch is mocked and the site-wide dialogs
// (window.ScoutMagicConfirm / window.ScoutMagicToast, loaded by
// base.html.twig in production) are stubbed, so the answer to the
// start-sending confirmation is something the test chooses rather than
// something a real modal has to be clicked for.
//
// Exercises the REAL public/assets/js/mass-mail-list.js (imported below,
// never reimplemented) on top of the real api.js envelope, with the
// server-side payload handed over in the page's JSON island exactly as
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

/**
 * Installs a page's server data the way the template does — a
 * `<script type="application/json">` island — rather than an inline
 * assignment to a window global. ScoutMagicApi.pageData() reads it.
 */
function installIsland(id, data) {
    document.getElementById(id)?.remove();
    if (data === undefined) {
        return;
    }
    const el = document.createElement('script');
    el.type = 'application/json';
    el.id = id;
    el.textContent = JSON.stringify(data);
    document.body.appendChild(el);
}

const START_SENDING_MESSAGE =
    'Lancer l\'envoi ? La liste des destinataires sera figée et l\'envoi ne pourra plus être annulé.';

/**
 * The send button asks the server how many people this would reach before
 * it asks the manager anything. Every test in this file therefore has to
 * answer that request first, so the helper below routes it.
 *
 * @param {{count: number, kind?: string}|null} estimate null = the request fails
 */
function recipientCountFetch(estimate) {
    return (url) => {
        const path = String(url);
        if (path.endsWith('/recipient-count')) {
            return estimate === null
                ? jsonResponse({ success: false, error: 'Email introuvable.' })
                : jsonResponse({ success: true, count: estimate.count, kind: estimate.kind || 'members' });
        }
        // A successful status change re-opens the email; answer that the
        // way the server would, or the reload lands on a payload with no
        // email in it.
        if (path === '/mass-mail/7') {
            return jsonResponse(email({ status: 'sending' }));
        }

        return jsonResponse({ success: true });
    };
}

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
                <div class="d-none" id="mm-future-year-warning"></div>
            </div>

            <div id="mm-merge-list-note" class="d-none"></div>
            <div id="mm-external-list-note" class="d-none"></div>
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
            // Contributed by another module (Email::LIST_TYPE_EXTERNAL) — only
            // present when that module is enabled, hence a plain fixture entry
            // rather than a hardcoded option the script appends itself.
            { list_type: 'external', list_section_id: null, label: 'Inscriptions 2026-2027', description: 'Demandes encodées' },
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
 * @param {object} [data] the page's JSON-island payload
 * @param {object} [opened] the email GET response
 */
async function bootAndOpen(data = listData(), opened = email()) {
    buildDom();
    installIsland('mass-mail-list-data', data);
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
        global.fetch = vi.fn(recipientCountFetch({ count: 42 }));

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith({
            message: 'Cet email partira à 42 personnes. ' + START_SENDING_MESSAGE,
            confirmLabel: 'Envoyer',
            // Irreversible, but it destroys nothing: sending is the point of
            // the screen, so the primary button, not the danger one.
            variant: 'primary',
        });
        expect(nativeConfirm).not.toHaveBeenCalled();
    });

    it('counts the people, so 42 and 400 are not the same question', async () => {
        // A wrong list selection looks exactly like a right one until the
        // mail is out, and nothing can be recalled after that.
        await bootAndOpen();
        global.fetch = vi.fn(recipientCountFetch({ count: 400 }));

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask.mock.calls[0][0].message)
            .toContain('partira à 400 personnes');
    });

    it('says one person in the singular', async () => {
        await bootAndOpen();
        global.fetch = vi.fn(recipientCountFetch({ count: 1 }));

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask.mock.calls[0][0].message)
            .toContain('partira à 1 personne. ');
    });

    it('counts rows of the file for a mail merge, not people', async () => {
        await bootAndOpen();
        global.fetch = vi.fn(recipientCountFetch({ count: 12, kind: 'rows' }));

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask.mock.calls[0][0].message)
            .toContain('12 lignes du fichier');
    });

    it('says so when the list currently designates nobody', async () => {
        await bootAndOpen();
        global.fetch = vi.fn(recipientCountFetch({ count: 0 }));

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask.mock.calls[0][0].message)
            .toBe('Cette liste ne désigne actuellement personne. ' + START_SENDING_MESSAGE);
    });

    it('still asks, without a number, when the count cannot be had', async () => {
        // A failed count must not become a silent send, and must not
        // become a send that cannot happen either.
        await bootAndOpen();
        global.fetch = vi.fn(recipientCountFetch(null));

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask.mock.calls[0][0].message).toBe(START_SENDING_MESSAGE);
    });

    it('posts nothing when the confirmation resolves false — an email cannot be un-sent', async () => {
        await bootAndOpen();
        global.fetch = vi.fn(recipientCountFetch({ count: 42 }));
        window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled();
        // The count is a GET and changes nothing; the status POST is what
        // must not have happened.
        expect(fetch.mock.calls.every(([url]) => !String(url).endsWith('/status'))).toBe(true);
    });

    it('posts start_sending for the open email when it resolves true', async () => {
        await bootAndOpen();
        global.fetch = vi.fn(recipientCountFetch({ count: 42 }));

        document.getElementById('mm-start-sending-btn').click();
        await settle();

        const post = fetch.mock.calls.find(([url]) => String(url).endsWith('/status'));
        expect(post[0]).toBe('/mass-mail/7/status');
        expect(JSON.parse(post[1].body))
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
        installIsland('mass-mail-list-data', listData());
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

    // The future-year warning (spec §18.4) lives in a box this fixture
    // does not carry — and the dialog must open all the same. This is the
    // regression that broke every test in this file once: the warning
    // helper runs on every draft that opens, and reading .textContent off
    // a null aborted the whole dialog.
    it('opens a draft normally on a page with no warning box', async () => {
        await bootAndOpen(listData(), email({ scout_year_ids: [2] }));
        document.getElementById('mm-future-year-warning').remove();

        // Re-open, with the box gone: reading .textContent off a null is
        // what aborted the whole dialog and broke every test in this file.
        document.querySelector('.mm-open-btn').dispatchEvent(new Event('click'));
        await settle();

        expect(document.getElementById('mm-future-year-warning')).toBeNull();
        expect(/** @type {HTMLInputElement} */ (document.getElementById('mm-subject')).value)
            .not.toBe('');
    });

    it('shows the server\'s warning for a future year, and only for it', async () => {
        const data = listData();
        data.scoutYears.next.warning = "Cette liste vise l'année 2027-2028. …";
        data.scoutYears.next.available = true;

        await bootAndOpen(data, email({ scout_year_ids: [2] }));

        const box = document.getElementById('mm-future-year-warning');
        expect(box.classList.contains('d-none')).toBe(true);

        const next = /** @type {HTMLInputElement} */ (document.getElementById('mm-year-next'));
        next.checked = true;
        next.dispatchEvent(new Event('change', { bubbles: true }));

        expect(box.classList.contains('d-none')).toBe(false);
        expect(box.textContent).toBe("Cette liste vise l'année 2027-2028. …");

        next.checked = false;
        next.dispatchEvent(new Event('change', { bubbles: true }));
        expect(box.classList.contains('d-none')).toBe(true);
    });

    // A list contributed by another module (Email::LIST_TYPE_EXTERNAL —
    // today the registration module's) is resolved against its OWN fixed
    // target year: Service\MailingListService::resolveMembersForYears()
    // never re-scopes it, so whatever the checkboxes said was already being
    // ignored server-side. Offering them advertised a choice that does not
    // exist; hiding them without a word would look like a glitch, hence the
    // note that replaces the block.
    describe('a list whose year is not the chief\'s to choose', () => {
        /** @param {string} value */
        async function selectList(value) {
            const select = /** @type {HTMLSelectElement} */ (document.getElementById('mm-list'));
            select.value = value;
            select.dispatchEvent(new Event('change'));
            await settle();
        }

        /** @param {string} id */
        function hidden(id) {
            return document.getElementById(id).classList.contains('d-none');
        }

        it('hides the scout-year block and explains why, in French', async () => {
            await bootAndOpen(listData(), email({ status: 'draft' }));

            await selectList('external:');

            expect(hidden('mm-scout-year-zone')).toBe(true);
            // The note's French wording lives in the template, not here —
            // it is asserted where it is written (Tests\Modules\MassMail\
            // EmailListRenderingTest) and read off the real page by
            // tests/e2e/specs/mass-mail.spec.js.
            expect(hidden('mm-external-list-note')).toBe(false);
            // The mail-merge note is the model, not a synonym: the file zone
            // and its own note stay out of it.
            expect(hidden('mm-merge-list-note')).toBe(true);
            expect(hidden('mm-merge-zone')).toBe(true);
        });

        it('brings the years back, and drops the note, on any ordinary list', async () => {
            await bootAndOpen(listData(), email({ status: 'draft' }));

            await selectList('external:');
            await selectList('default_section:2');

            expect(hidden('mm-scout-year-zone')).toBe(false);
            expect(hidden('mm-external-list-note')).toBe(true);
        });

        it('hides the years for a mail merge too, without borrowing the other note', async () => {
            await bootAndOpen(listData(), email({ status: 'draft' }));

            await selectList('mail_merge:');

            expect(hidden('mm-scout-year-zone')).toBe(true);
            expect(hidden('mm-merge-list-note')).toBe(false);
            expect(hidden('mm-external-list-note')).toBe(true);
        });

        it('applies the rule when an existing email of that list type is opened', async () => {
            // Not only on a change event: applyStatusUi() re-runs the same
            // rule, which is the path the "Ouvrir" button takes.
            await bootAndOpen(listData(), email({
                status: 'draft',
                list_type: 'external',
                list_id: null,
                list_section_id: null,
            }));

            expect(/** @type {HTMLSelectElement} */ (document.getElementById('mm-list')).value)
                .toBe('external:');
            expect(hidden('mm-scout-year-zone')).toBe(true);
            expect(hidden('mm-external-list-note')).toBe(false);
        });
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

// The page's server data reaches this file as parsed JSON from a
// `<script type="application/json">` island — DOM text, which is exactly
// the source CodeQL follows into an innerHTML sink. The list markup is
// built by string concatenation, so EVERY interpolated value has to be
// escaped, ids and enums included: they are integers and server-side
// enums today, and that is the kind of assumption that rots.
describe('mass-mail-list.js: no value reaches the option markup unescaped', () => {
    it('a quote in a list id or type cannot open an attribute of its own', async () => {
        await bootAndOpen(listData({
            defaultLists: [{
                list_type: 'default_section" data-injected="1',
                list_section_id: '2" data-injected="1',
                label: 'Louveteaux',
                description: 'Section',
            }],
            customLists: [{ id: '9" data-injected="1', name: 'Anciens', description: 'Liste maison' }],
        }));

        expect(document.querySelectorAll('#mm-list [data-injected]')).toHaveLength(0);
        // …and the value survives intact as text, rather than being dropped.
        const option = [...document.querySelectorAll('#mm-list option')]
            .find((o) => o.textContent === 'Anciens');
        expect(option.dataset.listId).toBe('9" data-injected="1');
    });

    it('a quote in a scout year label or id cannot break out either', async () => {
        await bootAndOpen(listData({
            scoutYears: {
                previous: { id: '1" data-injected="1', label: '2024-2025', available: true },
                current: { id: 2, label: '2025-2026', available: true },
                next: { id: 3, label: '<img src=x onerror=alert(1)>', available: false },
            },
        }));

        expect(document.querySelectorAll('#mm-scout-year-group [data-injected]')).toHaveLength(0);
        expect(document.querySelector('#mm-scout-year-group img')).toBeNull();
        expect(document.querySelector('label[for="mm-year-next"]').textContent)
            .toBe('<img src=x onerror=alert(1)>');
    });
});
