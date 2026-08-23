// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked throughout. Exercises the REAL
// implementation in public/assets/js/setup.js (imported below, never
// reimplemented here) — the first-run installer wizard, which until this
// file existed was the largest first-party script with no test of any
// kind: if it breaks, a new ScoutMagic install cannot be created.
//
// The script is one IIFE that reads its whole DOM at import time and
// assumes the setup page's fixed skeleton (no per-element guards for the
// core controls) — so every scenario builds the full skeleton and
// re-imports (vi.resetModules() + dynamic import), the same pattern as
// tests/js/maintenance.test.js.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function el(html) {
    const div = document.createElement('div');
    div.innerHTML = html.trim();
    return div.firstElementChild;
}

function jsonResponse(data) {
    return Promise.resolve({ json: () => Promise.resolve(data) });
}

/** Let the fetch().then() chains of the script settle. */
async function settle() {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
}

/**
 * The setup page's skeleton — every element the script dereferences
 * unconditionally, plus the optional sections the scenarios drive.
 *
 * @param {{ initialized?: boolean, installAction?: string }} [options]
 */
function buildDom(options = {}) {
    document.body.innerHTML = `
        <form id="setup-form" data-initialized="${options.initialized ? '1' : '0'}">
            <input type="hidden" name="_csrf_token" value="setup-tok">
            <input id="db_host" value="127.0.0.1">
            <input id="db_port" value="3306">
            <input id="db_name" value="scoutmagic">
            <input id="db_user" value="root">
            <input id="db_password" value="secret">
            <select id="mail_mode">
                <option value="local" selected>local</option>
                <option value="smtp">smtp</option>
            </select>
            <div id="smtp-fields" style="display:none;">
                <input id="smtp_host" value="smtp.example.be">
                <input id="smtp_port" value="587">
                <input id="smtp_user" value="mailer">
                <input id="smtp_password" value="mailpass">
            </div>
            <input id="mail_from_address" value="unite@exemple.be">
            <input id="mail_from_name" value="Unité">
            <input id="short_name" value="unite">
            <input id="dkim_selector" value="scoutmagic">
            <input id="dmarc_report_email" value="">
            <button type="button" id="btn-test-db"${options.installAction ? ` data-action="${options.installAction}"` : ''}>Tester</button>
            <span id="db-spinner" class="d-none"></span>
            <div id="db-test-result"></div>
            <div id="db-not-empty-warning" class="d-none">
                <span id="db-not-empty-count"></span>
                <button type="button" id="btn-backup-empty-db">Sauvegarder et vider</button>
                <span id="backup-empty-spinner" class="d-none"></span>
                <div id="backup-empty-result"></div>
                <button type="button" id="btn-empty-without-backup" class="d-none">Vider sans sauvegarde</button>
            </div>
            <div id="dkim-key-section">
                <button type="button" id="btn-generate-dkim">Générer</button>
                <span id="dkim-gen-spinner" class="d-none"></span>
                <div id="dkim-gen-result"></div>
            </div>
            <input id="test_email_recipient" value="">
            <button type="button" id="btn-test-email">Tester l'email</button>
            <span id="email-spinner" class="d-none"></span>
            <div id="email-test-result"></div>
            <button type="button" id="btn-check-dns">Vérifier DNS</button>
            <span id="dns-spinner" class="d-none"></span>
            <div id="dns-records"></div>
            <button type="button" id="btn-save" disabled>Enregistrer</button>
            <div id="save-hint">Testez d'abord la connexion.</div>
        </form>
    `;
}

async function boot(options) {
    buildDom(options);
    vi.resetModules();
    await import('../../public/assets/js/setup.js');
}

beforeEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    global.fetch = vi.fn();
    // The shared confirmation, stubbed: the installer's one destructive
    // button must ask before it empties anything.
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
});

describe('setup.js: SMTP fields visibility', () => {
    it('hides the SMTP block in local mode and reveals it when smtp is picked', async () => {
        await boot();
        const smtpFields = document.getElementById('smtp-fields');
        expect(smtpFields.style.display).toBe('none');

        const mode = /** @type {HTMLSelectElement} */ (document.getElementById('mail_mode'));
        mode.value = 'smtp';
        mode.dispatchEvent(new Event('change'));
        expect(smtpFields.style.display).toBe('block');

        mode.value = 'local';
        mode.dispatchEvent(new Event('change'));
        expect(smtpFields.style.display).toBe('none');
    });
});

describe('setup.js: save gating on the DB test', () => {
    it('keeps Save locked on a fresh install until the database test passed', async () => {
        await boot();
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('btn-save')).disabled).toBe(true);
        expect(document.getElementById('save-hint').style.display).not.toBe('none');
    });

    it('unlocks Save from the start on an already-initialized instance', async () => {
        await boot({ initialized: true });
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('btn-save')).disabled).toBe(false);
        expect(document.getElementById('save-hint').style.display).toBe('none');
    });

    it('posts the five connection fields as FormData and unlocks Save on success', async () => {
        fetch.mockReturnValue(jsonResponse({ success: true }));
        await boot();

        document.getElementById('btn-test-db').click();
        await settle();

        expect(fetch).toHaveBeenCalledTimes(1);
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/setup/test-db');
        expect(init.method).toBe('POST');
        const body = /** @type {FormData} */ (init.body);
        expect(body.get('_csrf_token')).toBe('setup-tok');
        expect(body.get('db_host')).toBe('127.0.0.1');
        expect(body.get('db_name')).toBe('scoutmagic');
        expect(body.get('db_password')).toBe('secret');

        expect(document.getElementById('db-test-result').textContent).toContain('Connexion réussie');
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('btn-save')).disabled).toBe(false);
    });

    it('uses the button\'s data-action override (the first-run install endpoint) and reports the migration', async () => {
        fetch.mockReturnValue(jsonResponse({ success: true, migrated: true, statements_executed: 42, table_count: 17 }));
        await boot({ installAction: '/setup/install-database' });

        document.getElementById('btn-test-db').click();
        await settle();

        expect(fetch.mock.calls[0][0]).toBe('/setup/install-database');
        expect(document.getElementById('db-test-result').textContent).toContain('Base de données installée');
        expect(document.getElementById('db-test-result').textContent).toContain('42 instructions');
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('btn-save')).disabled).toBe(false);
    });

    it('re-locks Save and shows the failure when the test fails', async () => {
        fetch.mockReturnValue(jsonResponse({ success: false, message: 'Accès refusé.' }));
        await boot();

        document.getElementById('btn-test-db').click();
        await settle();

        expect(document.getElementById('db-test-result').textContent).toContain('Accès refusé.');
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('btn-save')).disabled).toBe(true);
        expect(document.getElementById('save-hint').style.display).toBe('block');
    });
});

describe('setup.js: non-empty database barrier', () => {
    it('blocks Save and reveals the backup-and-empty warning when tables already exist', async () => {
        fetch.mockReturnValue(jsonResponse({ success: true, has_existing_tables: true, table_count: 12 }));
        await boot();

        document.getElementById('btn-test-db').click();
        await settle();

        expect(document.getElementById('db-not-empty-warning').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('db-not-empty-count').textContent).toBe('12 tables');
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('btn-save')).disabled).toBe(true);
    });

    it('backs up, downloads, and chains straight into the install once the base is emptied', async () => {
        fetch
            .mockReturnValueOnce(jsonResponse({ success: true, table_count: 12, download_url: '/setup/download-backup' }))
            .mockReturnValueOnce(jsonResponse({ success: true }));
        await boot();

        // The script triggers the download through a temporary
        // <a download> — intercepted here both to assert it and because
        // jsdom would otherwise try (and loudly fail) to navigate.
        const downloadClicks = [];
        vi.spyOn(window.HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
            downloadClicks.push({ href: this.getAttribute('href'), download: this.hasAttribute('download') });
        });

        document.getElementById('btn-backup-empty-db').click();
        await settle();

        expect(downloadClicks).toEqual([{ href: '/setup/download-backup', download: true }]);

        expect(fetch.mock.calls[0][0]).toBe('/setup/backup-and-empty-db');
        const body = /** @type {FormData} */ (fetch.mock.calls[0][1].body);
        expect(body.get('db_name')).toBe('scoutmagic');
        expect(body.get('force_without_backup')).toBeNull();

        expect(document.getElementById('backup-empty-result').textContent).toContain('Sauvegarde téléchargée');
        // The chained install is the second fetch — no extra click needed.
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(fetch.mock.calls[1][0]).toBe('/setup/test-db');
    });

    it('only offers "empty without backup" when the BACKUP step is what failed', async () => {
        fetch.mockReturnValue(jsonResponse({ success: false, message: 'mysqldump indisponible.', backup_failed: true }));
        await boot();

        document.getElementById('btn-backup-empty-db').click();
        await settle();

        const emptyAnyway = document.getElementById('btn-empty-without-backup');
        expect(emptyAnyway.classList.contains('d-none')).toBe(false);
        expect(/** @type {HTMLButtonElement} */ (emptyAnyway).disabled).toBe(false);
    });

    it('the destructive "empty without backup" asks the shared confirmation first, and a refusal sends nothing', async () => {
        await boot();
        document.getElementById('btn-empty-without-backup').classList.remove('d-none');

        window.ScoutMagicConfirm.ask.mockResolvedValue(false);
        document.getElementById('btn-empty-without-backup').click();
        await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
        await settle();
        expect(fetch).not.toHaveBeenCalled();

        window.ScoutMagicConfirm.ask.mockResolvedValue(true);
        fetch
            .mockReturnValueOnce(jsonResponse({ success: true, backup_skipped: true, table_count: 12 }))
            .mockReturnValueOnce(jsonResponse({ success: true }));
        document.getElementById('btn-empty-without-backup').click();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        await settle();

        // Asked in French, with a button naming what it does, and asked
        // BEFORE the request that empties the database.
        expect(window.ScoutMagicConfirm.ask).toHaveBeenLastCalledWith(expect.objectContaining({
            message: 'Vider la base de données SANS sauvegarde ? Les données actuellement en base seront définitivement perdues.',
            confirmLabel: 'Vider sans sauvegarde',
        }));
        const body = /** @type {FormData} */ (fetch.mock.calls[0][1].body);
        expect(body.get('force_without_backup')).toBe('1');
        expect(document.getElementById('backup-empty-result').textContent).toContain('SANS sauvegarde');
    });
});

describe('setup.js: test email', () => {
    it('refuses an empty recipient without touching the network', async () => {
        await boot();
        document.getElementById('btn-test-email').click();
        await settle();

        expect(fetch).not.toHaveBeenCalled();
        expect(document.getElementById('email-test-result').textContent).toContain('Veuillez entrer une adresse email.');
    });

    it('sends every mail setting with the recipient, so the uninitialized flow can test unsaved values', async () => {
        fetch.mockReturnValue(jsonResponse({ success: true, message: 'Email envoyé.' }));
        await boot();

        /** @type {HTMLInputElement} */ (document.getElementById('test_email_recipient')).value = 'moi@exemple.be';
        document.getElementById('btn-test-email').click();
        await settle();

        const body = /** @type {FormData} */ (fetch.mock.calls[0][1].body);
        expect(fetch.mock.calls[0][0]).toBe('/setup/test-email');
        expect(body.get('recipient')).toBe('moi@exemple.be');
        expect(body.get('mail_mode')).toBe('local');
        expect(body.get('smtp_host')).toBe('smtp.example.be');
        expect(body.get('dkim_selector')).toBe('scoutmagic');
        expect(document.getElementById('email-test-result').textContent).toContain('Email envoyé.');
    });
});

describe('setup.js: DKIM generation', () => {
    it('rebuilds the key section in place with the public key and its copy button', async () => {
        fetch.mockReturnValue(jsonResponse({ success: true, public_key: 'v=DKIM1; p=MIIB' }));
        await boot();

        document.getElementById('btn-generate-dkim').click();
        await settle();

        const display = /** @type {HTMLInputElement} */ (document.getElementById('dkim-pubkey-display'));
        expect(display.value).toBe('v=DKIM1; p=MIIB');
        expect(document.querySelector('#dkim-key-section .btn-copy-value')).not.toBeNull();
        expect(document.getElementById('dkim-key-section').textContent).toContain('scoutmagic');
    });
});

describe('setup.js: DNS check', () => {
    it('asks for the sender address and selector before ever fetching', async () => {
        await boot();
        /** @type {HTMLInputElement} */ (document.getElementById('mail_from_address')).value = 'pas-un-email';

        document.getElementById('btn-check-dns').click();
        await settle();

        expect(fetch).not.toHaveBeenCalled();
        expect(document.getElementById('dns-records').textContent)
            .toContain("Veuillez remplir l'adresse d'expédition et le sélecteur DKIM.");
    });

    it('renders the three records with copyable short names, and attribute-escapes the values', async () => {
        fetch.mockReturnValue(jsonResponse({
            spf: { exists: true, expected: 'v=spf1 a mx ~all' },
            // A value carrying a double quote must survive the trip into a
            // value="..." attribute — the escapeAttr() path.
            dkim: { exists: false, expected: 'v=DKIM1; p="AAA"' },
            dmarc: { exists: false, expected: 'v=DMARC1; p=none; rua=mailto:unite@exemple.be' },
        }));
        await boot();
        /** @type {HTMLInputElement} */ (document.getElementById('dmarc_report_email')).value = 'unite@exemple.be';

        document.getElementById('btn-check-dns').click();
        await settle();

        const [url] = fetch.mock.calls[0];
        expect(url).toContain('/setup/dns?');
        expect(url).toContain('domain=exemple.be');
        expect(url).toContain('selector=scoutmagic');

        const records = document.getElementById('dns-records');
        const inputs = Array.from(records.querySelectorAll('input'));
        expect(inputs.map((input) => input.value)).toContain('scoutmagic._domainkey');
        expect(inputs.map((input) => input.value)).toContain('v=DKIM1; p="AAA"');
        expect(records.textContent).toContain('_dmarc');
    });

    it('tells the operator to generate the DKIM key first when the server says it is missing', async () => {
        fetch.mockReturnValue(jsonResponse({
            spf: { exists: true, expected: 'v=spf1 a mx ~all' },
            dkim: { key_missing: true },
            dmarc: { exists: false, expected: 'v=DMARC1; p=none' },
        }));
        await boot();

        document.getElementById('btn-check-dns').click();
        await settle();

        expect(document.getElementById('dns-records').textContent).toContain('Générez d\'abord la clé DKIM');
    });

    it('marks DMARC optional (no DNS edit pushed) when no report address was given', async () => {
        fetch.mockReturnValue(jsonResponse({
            spf: { exists: true, expected: 'v=spf1 a mx ~all' },
            dkim: { exists: true, expected: 'v=DKIM1; p=AAA' },
            dmarc: { exists: false, expected: 'v=DMARC1; p=none', actual: 'v=DMARC1; p=reject' },
        }));
        await boot();

        document.getElementById('btn-check-dns').click();
        await settle();

        const records = document.getElementById('dns-records').textContent;
        expect(records).toContain('Aucune adresse de rapport DMARC');
        expect(records).toContain('v=DMARC1; p=reject');
    });
});

describe('setup.js: copy buttons', () => {
    it('copies through the delegated handler, falling back to execCommand outside a secure context', async () => {
        await boot();
        document.getElementById('dns-records').innerHTML = `
            <div class="input-group">
                <input type="text" value="v=spf1 a mx ~all" readonly>
                <button type="button" class="btn-copy-value">Copier</button>
            </div>
        `;

        const execCommand = vi.fn(() => true);
        document.execCommand = execCommand;

        const button = document.querySelector('.btn-copy-value');
        button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await settle();

        expect(execCommand).toHaveBeenCalledWith('copy');
        expect(button.textContent).toBe('Copié !');
    });
});
