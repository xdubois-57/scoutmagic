// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked throughout. Exercises the REAL
// implementation in public/assets/js/maintenance.js (imported below, never
// reimplemented here). Six independent IIFEs, each gated on its own
// `if (!someElement) return;` and reading the DOM once at import time —
// same reason tests/js/settings.test.js and
// tests/js/notification-badge.test.js re-import per scenario
// (vi.resetModules() + dynamic import) rather than mutating shared state.
//
// Unlike retro-board.js/news-form-builder.js, nothing here needed a test
// seam: every function in this file is reachable through a real DOM event
// (click, input, submit) and its effect is observable in the DOM or via
// the mocked fetch calls, so the wiring itself is what's under test.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function el(html) {
    const div = document.createElement('div');
    div.innerHTML = html.trim();
    return div.firstElementChild;
}

function appendAll(...nodes) { nodes.forEach((n) => document.body.appendChild(n)); }

async function boot() {
    vi.resetModules();
    // The real toolboxes, never stubs — maintenance.js reaches
    // window.ScoutMagicApi/window.ScoutMagicToast at event time, and
    // base.html.twig guarantees this load order in production.
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/toast.js');
    await import('../../public/assets/js/maintenance.js');
}

function jsonResponse(data, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) });
}

beforeEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
    document.body.innerHTML = '';
    const meta = document.createElement('meta');
    meta.name = 'csrf-token';
    meta.content = 'tok';
    document.head.innerHTML = '';
    document.head.appendChild(meta);
    // The shared confirmation, stubbed: this suite pins that the two
    // irreversible operations ASK before they start, and that a refusal
    // sends nothing — the dialog itself is tests/js/confirm.test.js's.
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
});

describe('maintenance.js: auto-backup-frequency select', () => {
    function buildDom() {
        appendAll(
            el('<select id="auto-backup-frequency"><option value="daily">daily</option><option value="weekly">weekly</option></select>'),
            el('<input type="hidden" name="_csrf_token" value="form-tok">'),
        );
    }

    it('does nothing when the select is absent from the page', async () => {
        global.fetch = vi.fn();
        await boot();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('POSTs the new frequency with the site-wide CSRF token on change', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        await boot();

        const select = /** @type {HTMLSelectElement} */ (document.getElementById('auto-backup-frequency'));
        select.value = 'weekly';
        select.dispatchEvent(new Event('change'));

        expect(fetch).toHaveBeenCalledWith('/config/maintenance/backup/auto-frequency', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ frequency: 'weekly', _csrf_token: 'tok' }),
        }));
    });

    it('shows an "Enregistré." success toast on a successful save', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        await boot();

        document.getElementById('auto-backup-frequency').dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe('Enregistré.');
        expect(document.querySelector('.toast').className).toContain('text-bg-success');
    });

    it('shows no toast when the save fails', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: false }));
        await boot();

        document.getElementById('auto-backup-frequency').dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        await new Promise((resolve) => setTimeout(resolve, 0)); // let the response chain settle
        expect(document.querySelector('.toast-body')).toBeNull();
    });
});

describe('maintenance.js: full-backup-form + its poller', () => {
    function buildDom() {
        appendAll(
            el(`<form id="full-backup-form">
                <input type="radio" name="scope" value="all" checked>
                <button type="submit" id="full-backup-submit"></button>
            </form>`),
            el('<input id="full-backup-password" value="">'),
            el('<div id="full-backup-progress" class="d-none"></div>'),
            el('<div id="full-backup-error" class="d-none"></div>'),
        );
    }

    async function submit(password = 'pw') {
        buildDom();
        /** @type {HTMLInputElement} */ (document.getElementById('full-backup-password')).value = password;
        await boot();
        document.getElementById('full-backup-form').dispatchEvent(new Event('submit', { cancelable: true }));
    }

    it('never submits with no scope selected', async () => {
        buildDom();
        document.querySelector('input[name="scope"]').checked = false;
        /** @type {HTMLInputElement} */ (document.getElementById('full-backup-password')).value = 'pw';
        global.fetch = vi.fn();
        await boot();
        document.getElementById('full-backup-form').dispatchEvent(new Event('submit', { cancelable: true }));
        expect(fetch).not.toHaveBeenCalled();
    });

    it('never submits with an empty password', async () => {
        buildDom();
        global.fetch = vi.fn();
        await boot();
        document.getElementById('full-backup-form').dispatchEvent(new Event('submit', { cancelable: true }));
        expect(fetch).not.toHaveBeenCalled();
    });

    it('POSTs the checked scope, the password and the CSRF token', async () => {
        global.fetch = vi.fn(() => jsonResponse({}));
        await submit('s3cret');
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(fetch).toHaveBeenCalledWith('/config/maintenance/backup/full', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ scope: 'all', password: 's3cret', _csrf_token: 'tok' }),
        }));
    });

    it('disables the submit button and shows progress immediately on submit', async () => {
        global.fetch = vi.fn(() => new Promise(() => {})); // never resolves
        await submit();
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('full-backup-submit')).disabled).toBe(true);
        expect(document.getElementById('full-backup-progress').classList.contains('d-none')).toBe(false);
    });

    it('shows the server error and re-enables the button when the launch itself fails', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Espace disque insuffisant.' }));
        await submit();
        await vi.waitFor(() => expect(document.getElementById('full-backup-error').textContent).toBe('Espace disque insuffisant.'));
        expect(document.getElementById('full-backup-error').classList.contains('d-none')).toBe(false);
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('full-backup-submit')).disabled).toBe(false);
        expect(document.getElementById('full-backup-progress').classList.contains('d-none')).toBe(true);
    });

    it('shows a generic error when the launch request itself fails over the network', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await submit();
        await vi.waitFor(() => expect(document.getElementById('full-backup-error').textContent).toBe('Erreur réseau.'));
    });

    it('reloads the page once polling reports the backup completed', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, backup_id: 42 }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'completed' }) });
        Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
        vi.useFakeTimers();

        await submit();
        await vi.advanceTimersByTimeAsync(3000);

        expect(fetch).toHaveBeenNthCalledWith(2, '/api/maintenance/backup-status/42', expect.anything());
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('shows the failure message and stops polling once the backup fails', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, backup_id: 42 }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'failed', error_message: 'Disque plein.' }) });
        vi.useFakeTimers();

        await submit();
        await vi.advanceTimersByTimeAsync(3000);

        expect(document.getElementById('full-backup-error').textContent).toBe('Disque plein.');
        const callCountAfterFailure = fetch.mock.calls.length;
        await vi.advanceTimersByTimeAsync(3000);
        expect(fetch.mock.calls.length).toBe(callCountAfterFailure); // polling actually stopped
    });

    it('keeps polling on a pending status and on a transient poll network error', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, backup_id: 42 }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'pending' }) })
            .mockRejectedValueOnce(new Error('blip'))
            .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'completed' }) });
        Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
        vi.useFakeTimers();

        await submit();
        await vi.advanceTimersByTimeAsync(3000); // pending
        await vi.advanceTimersByTimeAsync(3000); // network error
        await vi.advanceTimersByTimeAsync(3000); // completed

        expect(window.location.reload).toHaveBeenCalled();
        expect(fetch).toHaveBeenCalledTimes(4);
    });
});

describe('maintenance.js: wireInstallForm() — wired for both update forms', () => {
    // progressEl/errorEl are looked up as descendants of the submitted form
    // itself (form.querySelector('[id$="-progress"]')), with a page-level
    // #update-install-progress/-error fallback only reached when a form has
    // no such descendant — nested here so both wirings exercise the same
    // (primary, descendant) path regardless of which form id is used.
    function buildDom(formId = 'update-install-form') {
        appendAll(el(`<form id="${formId}">
            <button type="submit"></button>
            <input type="hidden" name="_csrf_token" value="form-tok">
            <div id="${formId}-progress" class="d-none"><span data-role="migration-detail"></span></div>
            <div id="${formId}-error" class="d-none"></div>
        </form>`));
    }

    it('does nothing when neither install form is present', async () => {
        global.fetch = vi.fn();
        await boot();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('wires the always-rendered update-install-form independently of the check-now dialog form', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn(() => jsonResponse({}));
        await boot();
        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/config/maintenance/update/install', expect.anything()));
    });

    it('wires the check-now dialog\'s own install form the same way', async () => {
        buildDom('update-check-now-install-form');
        global.fetch = vi.fn(() => jsonResponse({}));
        await boot();
        document.getElementById('update-check-now-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/config/maintenance/update/install', expect.anything()));
    });

    it('POSTs the site-wide CSRF token from the meta tag (the toolbox reads no form-level token)', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn(() => jsonResponse({}));
        await boot();
        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/config/maintenance/update/install', expect.objectContaining({
            body: JSON.stringify({ _csrf_token: 'tok' }),
        })));
    });

    it('disables the submit button and shows progress on submit', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn(() => new Promise(() => {}));
        await boot();
        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        expect(document.querySelector('#update-install-form button[type="submit"]').disabled).toBe(true);
        expect(document.getElementById('update-install-form-progress').classList.contains('d-none')).toBe(false);
    });

    it('reloads on a completed install', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, history_id: 7 }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'completed' }) });
        Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
        vi.useFakeTimers();
        await boot();

        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.advanceTimersByTimeAsync(3000);

        expect(fetch).toHaveBeenNthCalledWith(2, '/api/maintenance/update-status/7', expect.anything());
        expect(window.location.reload).toHaveBeenCalled();
    });

    it('shows an error and stops polling on a rolled_back install (a state full-backup polling does not have)', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, history_id: 7 }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'rolled_back', error_message: 'Migration invalide.' }) });
        vi.useFakeTimers();
        await boot();

        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.advanceTimersByTimeAsync(3000);

        expect(document.getElementById('update-install-form-error').textContent).toBe('Migration invalide.');
        const countAfterFailure = fetch.mock.calls.length;
        await vi.advanceTimersByTimeAsync(3000);
        expect(fetch.mock.calls.length).toBe(countAfterFailure);
    });

    it('shows the server error and re-enables the button when the launch itself fails', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Déjà en cours.' }));
        await boot();
        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(document.getElementById('update-install-form-error').textContent).toBe('Déjà en cours.'));
        expect(document.querySelector('#update-install-form button[type="submit"]').disabled).toBe(false);
    });

    it('shows a generic error on a network failure at launch', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();
        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(document.getElementById('update-install-form-error').textContent).toBe('Erreur réseau.'));
    });

    // While the schema migration runs, public/index.php short-circuits every
    // request before routing and serves the progress page — HTML, with a 200.
    // This poller used to sit on that for the whole window, on the one URL
    // that could not answer it.
    it('reloads onto the progress page when the poll gets a 200 that is not JSON', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, history_id: 7 }) })
            // What fetch does with an HTML body and Accept: application/json.
            .mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.reject(new SyntaxError('Unexpected token <')) });
        Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
        vi.useFakeTimers();
        await boot();

        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.advanceTimersByTimeAsync(3000);

        expect(window.location.reload).toHaveBeenCalled();
        // And it stopped: a reloading page must not keep firing requests.
        const after = fetch.mock.calls.length;
        await vi.advanceTimersByTimeAsync(6000);
        expect(fetch.mock.calls.length).toBe(after);
    });

    // A real network failure is a different thing and must stay a hiccup:
    // getJson answers status 0 there, never 200. Reloading on it would throw
    // away a perfectly good page every time the wifi blinked.
    it('keeps polling through a genuine network failure instead of reloading', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, history_id: 7 }) })
            .mockRejectedValueOnce(new TypeError('Failed to fetch'))
            .mockResolvedValue({ json: () => Promise.resolve({ status: 'installing' }) });
        Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
        vi.useFakeTimers();
        await boot();

        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.advanceTimersByTimeAsync(3000);
        expect(window.location.reload).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(3000);
        expect(fetch).toHaveBeenCalledTimes(3);
    });

    // The `migrating` step is the one that runs for minutes, and each poll now
    // drives a slice of it server-side; without this readout the spinner is
    // indistinguishable from a stuck update.
    it('reports the schema migration progress reported by the poll', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, history_id: 7 }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'migrating', migration_progress: 0.42 }) });
        vi.useFakeTimers();
        await boot();

        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.advanceTimersByTimeAsync(3000);

        expect(document.querySelector('#update-install-form-progress [data-role="migration-detail"]').textContent)
            .toBe(' Migration du schéma : 42 %.');
    });

    it('clears the readout on a step that is not migrating, and on a migrating poll that reports no progress', async () => {
        buildDom('update-install-form');
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, history_id: 7 }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'migrating', migration_progress: 0.42 }) })
            // A slice that threw reports a null progress rather than a 500.
            .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'migrating', migration_progress: null }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'installing' }) });
        vi.useFakeTimers();
        await boot();
        const detail = () => document.querySelector('#update-install-form-progress [data-role="migration-detail"]').textContent;

        document.getElementById('update-install-form').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.advanceTimersByTimeAsync(3000);
        expect(detail()).toBe(' Migration du schéma : 42 %.');

        await vi.advanceTimersByTimeAsync(3000);
        expect(detail()).toBe('');

        await vi.advanceTimersByTimeAsync(3000);
        expect(detail()).toBe('');
    });
});

describe('maintenance.js: "Vérifier maintenant" (update-check-now)', () => {
    function buildDom() {
        appendAll(
            el('<button id="update-check-now"></button>'),
            el('<div id="update-check-now-progress" class="d-none"></div>'),
            el('<div id="update-check-now-error" class="d-none"></div>'),
            el('<div id="update-check-now-dialog" class="d-none"></div>'),
            el('<span id="update-check-now-message"></span>'),
            el('<div id="update-check-now-notes"></div>'),
            el('<button id="update-check-now-dismiss"></button>'),
        );
    }

    function click() { document.getElementById('update-check-now').dispatchEvent(new Event('click')); }

    it('does nothing when the button is absent from the page', async () => {
        global.fetch = vi.fn();
        await boot();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('POSTs with the page-level CSRF token, disables the button and shows progress', async () => {
        buildDom();
        global.fetch = vi.fn(() => new Promise(() => {}));
        await boot();
        click();
        expect(fetch).toHaveBeenCalledWith('/config/maintenance/update/check-now', expect.objectContaining({
            method: 'POST', body: JSON.stringify({ _csrf_token: 'tok' }),
        }));
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('update-check-now')).disabled).toBe(true);
        expect(document.getElementById('update-check-now-progress').classList.contains('d-none')).toBe(false);
    });

    it('toasts "already up to date" and never opens the dialog when no update is available', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: true, update_available: false }));
        await boot();
        click();
        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe('Le site est déjà à jour.');
        expect(document.getElementById('update-check-now-dialog').classList.contains('d-none')).toBe(true);
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('update-check-now')).disabled).toBe(false);
    });

    it('populates the dialog with the version and pre-rendered notes HTML verbatim when an update is available', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({
            success: true, update_available: true, version: '2.4.0',
            notes_html: '<p>Corrige <strong>trois</strong> bugs.</p>',
        }));
        await boot();
        click();
        await vi.waitFor(() => expect(document.getElementById('update-check-now-dialog').classList.contains('d-none')).toBe(false));
        expect(document.getElementById('update-check-now-message').textContent).toBe('Nouvelle version disponible : 2.4.0');
        // Deliberately NOT escaped client-side — per the file's own comment,
        // notes_html is already HTML-escaped server-side by
        // Core\View\MarkdownRenderer before this ever runs.
        expect(document.getElementById('update-check-now-notes').innerHTML).toBe('<p>Corrige <strong>trois</strong> bugs.</p>');
    });

    it('flags an intermediate major step when the proposed version is not the newest one', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({
            success: true, update_available: true, version: '2.0.0', latest_version: '3.1.0',
        }));
        await boot();
        click();
        await vi.waitFor(() => expect(document.getElementById('update-check-now-dialog').classList.contains('d-none')).toBe(false));
        expect(document.getElementById('update-check-now-message').textContent).toBe(
            'Nouvelle version disponible : 2.0.0 — étape intermédiaire vers la version 3.1.0,'
            + " les versions majeures s'installent une par une.",
        );
    });

    it('says nothing about an intermediate step when the proposed version IS the newest one', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({
            success: true, update_available: true, version: '2.4.0', latest_version: '2.4.0',
        }));
        await boot();
        click();
        await vi.waitFor(() => expect(document.getElementById('update-check-now-dialog').classList.contains('d-none')).toBe(false));
        expect(document.getElementById('update-check-now-message').textContent).toBe('Nouvelle version disponible : 2.4.0');
    });

    it('falls back to empty notes when notes_html is absent, without throwing', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: true, update_available: true, version: '2.4.0' }));
        await boot();
        expect(() => click()).not.toThrow();
        await vi.waitFor(() => expect(document.getElementById('update-check-now-notes').innerHTML).toBe(''));
    });

    it('shows the server error and keeps the dialog closed when the check itself fails', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Jeton webhook invalide.' }));
        await boot();
        click();
        await vi.waitFor(() => expect(document.getElementById('update-check-now-error').textContent).toBe('Jeton webhook invalide.'));
        expect(document.getElementById('update-check-now-dialog').classList.contains('d-none')).toBe(true);
    });

    it('shows a generic error on a network failure', async () => {
        buildDom();
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();
        click();
        await vi.waitFor(() => expect(document.getElementById('update-check-now-error').textContent).toBe('Erreur réseau.'));
    });

    it('the dismiss button hides the dialog', async () => {
        buildDom();
        document.getElementById('update-check-now-dialog').classList.remove('d-none');
        global.fetch = vi.fn();
        await boot();
        document.getElementById('update-check-now-dismiss').dispatchEvent(new Event('click'));
        expect(document.getElementById('update-check-now-dialog').classList.contains('d-none')).toBe(true);
    });
});

describe('maintenance.js: auto-update settings', () => {
    function buildDom() {
        appendAll(
            el('<input type="checkbox" id="auto-update-enabled">'),
            el('<div id="auto-update-details" class="d-none"></div>'),
            el('<div id="auto-update-major-warning" class="d-none"></div>'),
            el('<div id="auto-update-dev-warning" class="d-none"></div>'),
            el('<div id="auto-update-schedule-section"></div>'),
            el('<div id="auto-update-branch-section" class="d-none"></div>'),
            el('<div id="auto-update-webhook-section" class="d-none"></div>'),
            el(`<div>
                <input type="radio" name="auto-update-level" value="patch" checked>
                <input type="radio" name="auto-update-level" value="minor">
                <input type="radio" name="auto-update-level" value="major">
                <input type="radio" name="auto-update-level" value="dev">
            </div>`),
            el('<a id="auto-update-semver-toggle" href="#"></a>'),
            el('<div id="auto-update-semver-explainer" class="d-none"></div>'),
            el('<button id="auto-update-save"></button>'),
            el('<div id="auto-update-saved" class="d-none"></div>'),
            el('<div id="auto-update-error" class="d-none"></div>'),
            el('<select id="auto-update-day"><option value="1" selected>1</option></select>'),
            el('<input id="auto-update-time" value="03:00">'),
            el('<input id="auto-update-branch" value="main">'),
            el('<button id="webhook-generate-secret" class="btn-outline-primary"><i class="bi bi-key"></i></button>'),
            el('<span id="webhook-secret-value"></span>'),
            el('<div id="webhook-secret-display" class="d-none"></div>'),
            el('<div id="webhook-setup-instructions"></div>'),
            el('<span id="webhook-status-badge" class="text-bg-secondary"></span>'),
            el('<span id="webhook-generate-secret-label"></span>'),
        );
    }

    function selectLevel(value) {
        const radio = document.querySelector(`input[name="auto-update-level"][value="${value}"]`);
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
    }

    it('does nothing when the enabled checkbox is absent', async () => {
        global.fetch = vi.fn();
        await boot();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('toggles the details section with the enabled checkbox', async () => {
        buildDom();
        await boot();
        const checkbox = /** @type {HTMLInputElement} */ (document.getElementById('auto-update-enabled'));
        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change'));
        expect(document.getElementById('auto-update-details').classList.contains('d-none')).toBe(false);
    });

    // The four-section visibility matrix: only one warning is ever shown at
    // once, and 'dev' is the only level that hides the schedule section
    // while showing the branch/webhook sections — everything else is the
    // reverse. Table-driven since it's the densest branching in the file.
    it.each([
        { level: 'patch', major: true, dev: true, schedule: false, branch: true, webhook: true },
        { level: 'minor', major: true, dev: true, schedule: false, branch: true, webhook: true },
        { level: 'major', major: false, dev: true, schedule: false, branch: true, webhook: true },
        { level: 'dev', major: true, dev: false, schedule: true, branch: false, webhook: false },
    ])('level=$level sets the right section visibility', async ({ level, major, dev, schedule, branch, webhook }) => {
        buildDom();
        await boot();
        selectLevel(level);
        expect(document.getElementById('auto-update-major-warning').classList.contains('d-none')).toBe(major);
        expect(document.getElementById('auto-update-dev-warning').classList.contains('d-none')).toBe(dev);
        expect(document.getElementById('auto-update-schedule-section').classList.contains('d-none')).toBe(schedule);
        expect(document.getElementById('auto-update-branch-section').classList.contains('d-none')).toBe(branch);
        expect(document.getElementById('auto-update-webhook-section').classList.contains('d-none')).toBe(webhook);
    });

    it('an unchecked radio\'s own change event is a no-op (only the newly-checked one drives visibility)', async () => {
        buildDom();
        await boot();
        selectLevel('major'); // major warning now visible
        const patchRadio = document.querySelector('input[name="auto-update-level"][value="patch"]');
        patchRadio.checked = false;
        patchRadio.dispatchEvent(new Event('change'));
        expect(document.getElementById('auto-update-major-warning').classList.contains('d-none')).toBe(false);
    });

    it('the semver toggle link expands/collapses the explainer and never navigates', async () => {
        buildDom();
        await boot();
        const link = document.getElementById('auto-update-semver-toggle');
        const evt = new MouseEvent('click', { cancelable: true });
        link.dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(true);
        expect(document.getElementById('auto-update-semver-explainer').classList.contains('d-none')).toBe(false);
    });

    it('save POSTs enabled/level/day/time/branch and the CSRF token', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        await boot();
        /** @type {HTMLInputElement} */ (document.getElementById('auto-update-enabled')).checked = true;
        selectLevel('dev');
        document.getElementById('auto-update-save').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/config/maintenance/auto-update/save', expect.objectContaining({
            body: JSON.stringify({ enabled: true, level: 'dev', day: '1', time: '03:00', branch: 'main', _csrf_token: 'tok' }),
        })));
    });

    it('defaults level to "patch" when nothing is checked', async () => {
        buildDom();
        document.querySelectorAll('input[name="auto-update-level"]').forEach((r) => { r.checked = false; });
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        await boot();
        document.getElementById('auto-update-save').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/config/maintenance/auto-update/save', expect.objectContaining({
            body: expect.stringContaining('"level":"patch"'),
        })));
    });

    it('shows an "Enregistré." success toast on a successful save', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        await boot();
        document.getElementById('auto-update-save').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe('Enregistré.');
        expect(document.querySelector('.toast').className).toContain('text-bg-success');
    });

    it('shows the server error on a failed save', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Format d\'heure invalide.' }));
        await boot();
        document.getElementById('auto-update-save').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('auto-update-error').textContent).toBe('Format d\'heure invalide.'));
    });

    it('shows a generic error on a network failure while saving', async () => {
        buildDom();
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();
        document.getElementById('auto-update-save').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('auto-update-error').textContent).toBe('Erreur réseau.'));
    });

    it('webhook secret generation reveals the secret and switches the badge/button/icon to the "configured" state', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: true, secret: 'whsec_abcdef' }));
        await boot();
        document.getElementById('webhook-generate-secret').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(document.getElementById('webhook-secret-value').textContent).toBe('whsec_abcdef'));
        expect(document.getElementById('webhook-secret-display').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('webhook-setup-instructions').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('webhook-status-badge').textContent).toBe('✓ Configuré');
        expect(document.getElementById('webhook-status-badge').classList.contains('text-bg-success')).toBe(true);
        expect(document.getElementById('webhook-status-badge').classList.contains('text-bg-secondary')).toBe(false);
        expect(document.getElementById('webhook-generate-secret-label').textContent).toBe('Régénérer le secret');
        expect(document.getElementById('webhook-generate-secret').classList.contains('btn-outline-secondary')).toBe(true);
        expect(document.querySelector('#webhook-generate-secret i').classList.contains('bi-arrow-repeat')).toBe(true);
    });

    it('re-enables the button and toasts the server error on a failed secret generation', async () => {
        buildDom();
        global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Session expirée.' }));
        await boot();
        document.getElementById('webhook-generate-secret').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe('Session expirée.');
        expect(document.querySelector('.toast').className).toContain('text-bg-danger');
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('webhook-generate-secret')).disabled).toBe(false);
        expect(document.getElementById('webhook-secret-display').classList.contains('d-none')).toBe(true);
    });

    it('re-enables the button and toasts a generic message on a network failure', async () => {
        buildDom();
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();
        document.getElementById('webhook-generate-secret').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe('Erreur réseau.');
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('webhook-generate-secret')).disabled).toBe(false);
    });
});

describe('maintenance.js: "Réinitialisation" — the destructive-action gates', () => {
    // wireKeywordGate()'s `update` runs only on the input's own 'input'
    // event (input.addEventListener('input', update)) — it is never called
    // eagerly when the gate is wired up. So the button's disabled attribute
    // on page load is entirely the server-rendered HTML's responsibility,
    // not this file's; these fixtures set it explicitly, matching what the
    // real Twig template does, rather than relying on this JS to do it.
    function buildResetSettings() {
        appendAll(el(`<form id="reset-settings-form">
            <input id="reset-settings-keyword">
            <button type="submit" id="reset-settings-submit" disabled></button>
            <div id="reset-settings-progress" class="d-none"></div>
            <div id="reset-settings-error" class="d-none"></div>
            <input type="hidden" name="_csrf_token" value="tok">
        </form>`));
    }

    function buildFullReset() {
        appendAll(el(`<form id="full-reset-form">
            <input type="checkbox" id="full-reset-checkbox">
            <input id="full-reset-keyword">
            <button type="submit" id="full-reset-submit" disabled></button>
            <div id="full-reset-progress" class="d-none"></div>
            <div id="full-reset-error" class="d-none"></div>
            <input type="hidden" name="_csrf_token" value="tok">
        </form>`));
    }

    function buildRestoreBackup() {
        appendAll(
            el(`<form id="restore-backup-form">
                <input id="restore-backup-keyword">
                <button type="submit" id="restore-backup-submit"></button>
            </form>`),
            el('<input type="radio" name="restore-source" id="restore-source-server" checked>'),
            el('<input type="radio" name="restore-source" id="restore-source-upload">'),
            el('<div id="restore-server-picker"></div>'),
            el('<div id="restore-upload-picker" class="d-none"></div>'),
        );
    }

    function type(input, value) {
        input.value = value;
        input.dispatchEvent(new Event('input'));
    }

    describe('wireKeywordGate() — reset-settings (single condition)', () => {
        // wireKeywordGate() itself never sets the initial state (see the
        // comment on buildResetSettings() above) — this test pins that
        // contract directly: importing the module must NOT touch a disabled
        // button that was never interacted with.
        it('does not touch the button\'s disabled state before any input event fires', async () => {
            buildResetSettings();
            await boot();
            expect(/** @type {HTMLButtonElement} */ (document.getElementById('reset-settings-submit')).disabled).toBe(true);
        });

        it('stays disabled on a near-miss (case, whitespace, partial match)', async () => {
            buildResetSettings();
            await boot();
            const input = document.getElementById('reset-settings-keyword');
            const submit = /** @type {HTMLButtonElement} */ (document.getElementById('reset-settings-submit'));
            ['reinitialiser', 'REINITIALISER ', ' REINITIALISER', 'REINITIALIS'].forEach((v) => {
                type(input, v);
                expect(submit.disabled).toBe(true);
            });
        });

        it('enables the button on an exact match, and re-disables if the text changes again', async () => {
            buildResetSettings();
            await boot();
            const input = document.getElementById('reset-settings-keyword');
            const submit = /** @type {HTMLButtonElement} */ (document.getElementById('reset-settings-submit'));
            type(input, 'REINITIALISER');
            expect(submit.disabled).toBe(false);
            type(input, 'REINITIALISER!');
            expect(submit.disabled).toBe(true);
        });
    });

    describe('wireKeywordGate() — full-reset (keyword AND checkbox, composed)', () => {
        it('typing the exact keyword alone is not enough without the checkbox', async () => {
            buildFullReset();
            await boot();
            type(document.getElementById('full-reset-keyword'), 'EFFACER');
            expect(/** @type {HTMLButtonElement} */ (document.getElementById('full-reset-submit')).disabled).toBe(true);
        });

        it('checking the box alone, with no keyword typed, is not enough either', async () => {
            buildFullReset();
            await boot();
            const checkbox = /** @type {HTMLInputElement} */ (document.getElementById('full-reset-checkbox'));
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change'));
            expect(/** @type {HTMLButtonElement} */ (document.getElementById('full-reset-submit')).disabled).toBe(true);
        });

        it('both together enable the button; unchecking afterwards disables it again', async () => {
            buildFullReset();
            await boot();
            const checkbox = /** @type {HTMLInputElement} */ (document.getElementById('full-reset-checkbox'));
            const submit = /** @type {HTMLButtonElement} */ (document.getElementById('full-reset-submit'));
            type(document.getElementById('full-reset-keyword'), 'EFFACER');
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change'));
            expect(submit.disabled).toBe(false);
            checkbox.checked = false;
            checkbox.dispatchEvent(new Event('change'));
            expect(submit.disabled).toBe(true);
        });
    });

    describe('reset-settings-form submit + pollResetStatus()', () => {
        async function submitReady() {
            buildResetSettings();
            type(document.getElementById('reset-settings-keyword'), 'REINITIALISER');
        }

        it('POSTs the typed keyword and CSRF token', async () => {
            await submitReady();
            global.fetch = vi.fn(() => jsonResponse({}));
            await boot();
            type(document.getElementById('reset-settings-keyword'), 'REINITIALISER');
            document.getElementById('reset-settings-form').dispatchEvent(new Event('submit', { cancelable: true }));
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/config/maintenance/reset/settings', expect.objectContaining({
                body: JSON.stringify({ confirm_keyword: 'REINITIALISER', _csrf_token: 'tok' }),
            })));
        });

        it('reloads the page once the reset completes', async () => {
            await submitReady();
            global.fetch = vi.fn()
                .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, action_id: 1 }) })
                .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'done' }) });
            Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
            vi.useFakeTimers();
            await boot();
            type(document.getElementById('reset-settings-keyword'), 'REINITIALISER');
            document.getElementById('reset-settings-form').dispatchEvent(new Event('submit', { cancelable: true }));
            await vi.advanceTimersByTimeAsync(3000);
            expect(window.location.reload).toHaveBeenCalled();
        });

        // The exact contract this file's own comment documents: a 404 on
        // reset-status means the action wiped its own tracking row along
        // with the settings table -- that IS success, not an error.
        it('treats a 404 on the status poll as success (the operation wiped its own tracking row)', async () => {
            await submitReady();
            global.fetch = vi.fn()
                .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, action_id: 1 }) })
                .mockResolvedValueOnce({ ok: false, status: 404, json: () => Promise.resolve({}) });
            Object.defineProperty(window, 'location', { configurable: true, value: { reload: vi.fn() } });
            vi.useFakeTimers();
            await boot();
            type(document.getElementById('reset-settings-keyword'), 'REINITIALISER');
            document.getElementById('reset-settings-form').dispatchEvent(new Event('submit', { cancelable: true }));
            await vi.advanceTimersByTimeAsync(3000);
            expect(window.location.reload).toHaveBeenCalled();
        });

        it('shows the failure message and re-arms the keyword gate on a failed reset', async () => {
            await submitReady();
            global.fetch = vi.fn()
                .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, action_id: 1 }) })
                .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'failed', error_message: 'Verrou déjà pris.' }) });
            vi.useFakeTimers();
            await boot();
            type(document.getElementById('reset-settings-keyword'), 'REINITIALISER');
            document.getElementById('reset-settings-form').dispatchEvent(new Event('submit', { cancelable: true }));
            await vi.advanceTimersByTimeAsync(3000);
            expect(document.getElementById('reset-settings-error').textContent).toBe('Verrou déjà pris.');
        });

        it('treats "canceled" the same as "failed"', async () => {
            await submitReady();
            global.fetch = vi.fn()
                .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, action_id: 1 }) })
                .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'canceled', error_message: 'Annulé.' }) });
            vi.useFakeTimers();
            await boot();
            type(document.getElementById('reset-settings-keyword'), 'REINITIALISER');
            document.getElementById('reset-settings-form').dispatchEvent(new Event('submit', { cancelable: true }));
            await vi.advanceTimersByTimeAsync(3000);
            expect(document.getElementById('reset-settings-error').textContent).toBe('Annulé.');
        });

        it('shows the server error and re-enables the gate\'s own re-check when the launch itself is refused', async () => {
            await submitReady();
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Mot-clé incorrect.' }));
            await boot();
            type(document.getElementById('reset-settings-keyword'), 'REINITIALISER');
            document.getElementById('reset-settings-form').dispatchEvent(new Event('submit', { cancelable: true }));
            await vi.waitFor(() => expect(document.getElementById('reset-settings-error').textContent).toBe('Mot-clé incorrect.'));
        });
    });

    describe('full-reset-form submit — the confirmation gate', () => {
        it('never calls fetch when the visitor declines the confirmation', async () => {
            buildFullReset();
            const checkbox = /** @type {HTMLInputElement} */ (document.getElementById('full-reset-checkbox'));
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change'));
            type(document.getElementById('full-reset-keyword'), 'EFFACER');
            global.fetch = vi.fn();
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            await boot();
            checkbox.checked = true; checkbox.dispatchEvent(new Event('change'));
            type(document.getElementById('full-reset-keyword'), 'EFFACER');
            const evt = new Event('submit', { cancelable: true });
            document.getElementById('full-reset-form').dispatchEvent(evt);
            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            await Promise.resolve();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('asks the site dialog — never the native box — before wiping everything', async () => {
            buildFullReset();
            const nativeConfirm = vi.fn(() => true);
            window.confirm = nativeConfirm;
            global.fetch = vi.fn(() => jsonResponse({ success: true, action_id: 1 }));
            await boot();
            const checkbox = /** @type {HTMLInputElement} */ (document.getElementById('full-reset-checkbox'));
            checkbox.checked = true; checkbox.dispatchEvent(new Event('change'));
            type(document.getElementById('full-reset-keyword'), 'EFFACER');
            document.getElementById('full-reset-form').dispatchEvent(new Event('submit', { cancelable: true }));

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(nativeConfirm).not.toHaveBeenCalled();
            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith(expect.objectContaining({
                message: 'Cette action est irréversible : toutes les données du site seront définitivement supprimées. Continuer ?',
                confirmLabel: 'Tout supprimer',
            }));
            expect(window.ScoutMagicConfirm.ask.mock.invocationCallOrder[0])
                .toBeLessThan(fetch.mock.invocationCallOrder[0]);
        });

        it('redirects to "/" both when the reset completes and when the status poll 404s (both mean everything, including the tracking row, is gone)', async () => {
            buildFullReset();
            const checkbox = /** @type {HTMLInputElement} */ (document.getElementById('full-reset-checkbox'));
            Object.defineProperty(window, 'location', { configurable: true, value: { href: '' } });
            global.fetch = vi.fn()
                .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, action_id: 1 }) })
                .mockResolvedValueOnce({ ok: false, status: 404, json: () => Promise.resolve({}) });
            vi.useFakeTimers();
            await boot();
            checkbox.checked = true; checkbox.dispatchEvent(new Event('change'));
            type(document.getElementById('full-reset-keyword'), 'EFFACER');
            document.getElementById('full-reset-form').dispatchEvent(new Event('submit', { cancelable: true }));
            await vi.advanceTimersByTimeAsync(3000);
            expect(window.location.href).toBe('/');
        });
    });

    // The dialog is asynchronous, so the submit event itself is ALWAYS
    // stopped and the confirmed restore is re-sent by hand with
    // form.submit() (which bypasses the listener — one confirmation, one
    // pass). That native call is what these tests watch for.
    describe('restore-backup-form submit — the confirmation gate + classic multipart submit', () => {
        it('declining the confirmation submits nothing and leaves the button usable', async () => {
            buildRestoreBackup();
            const nativeSubmit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
            window.ScoutMagicConfirm.ask = vi.fn(() => Promise.resolve(false));
            await boot();
            const evt = new Event('submit', { cancelable: true });
            document.getElementById('restore-backup-form').dispatchEvent(evt);
            await vi.waitFor(() => expect(window.ScoutMagicConfirm.ask).toHaveBeenCalled());
            await Promise.resolve();

            expect(evt.defaultPrevented).toBe(true);
            expect(nativeSubmit).not.toHaveBeenCalled();
            expect(/** @type {HTMLButtonElement} */ (document.getElementById('restore-backup-submit')).disabled).toBe(false);
        });

        it('accepting the confirmation disables the submit button and re-sends the multipart form itself', async () => {
            buildRestoreBackup();
            const nativeSubmit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
            await boot();
            const evt = new Event('submit', { cancelable: true });
            document.getElementById('restore-backup-form').dispatchEvent(evt);
            await vi.waitFor(() => expect(nativeSubmit).toHaveBeenCalledTimes(1));

            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledWith(expect.objectContaining({
                message: 'Cette action va remplacer les données actuelles par celles de la sauvegarde sélectionnée. Continuer ?',
                confirmLabel: 'Restaurer',
            }));
            expect(/** @type {HTMLButtonElement} */ (document.getElementById('restore-backup-submit')).disabled).toBe(true);
        });

        it('toggleRestoreSource() shows the upload picker and hides the server picker when "upload" is selected', async () => {
            buildRestoreBackup();
            await boot();
            const uploadRadio = /** @type {HTMLInputElement} */ (document.getElementById('restore-source-upload'));
            uploadRadio.checked = true;
            uploadRadio.dispatchEvent(new Event('change'));
            expect(document.getElementById('restore-upload-picker').classList.contains('d-none')).toBe(false);
            expect(document.getElementById('restore-server-picker').classList.contains('d-none')).toBe(true);
        });
    });

    describe('restore-backup-form submit — chunked upload of a large archive (audit M2)', () => {
        function buildChunkedRestore() {
            appendAll(
                el(`<form id="restore-backup-form">
                    <input type="hidden" name="_csrf_token" value="form-tok">
                    <input type="hidden" name="upload_id" id="restore-upload-id" value="">
                    <input type="file" id="restore-backup-file">
                    <input id="restore-backup-keyword">
                    <button type="submit" id="restore-backup-submit"></button>
                </form>`),
                el('<input type="radio" name="restore-source" id="restore-source-server">'),
                el('<input type="radio" name="restore-source" id="restore-source-upload" checked>'),
                el('<div id="restore-server-picker"></div>'),
                el('<div id="restore-upload-picker"></div>'),
                el('<div id="restore-backup-progress" class="d-none"></div>'),
                el('<div id="restore-backup-error" class="d-none"></div>'),
            );
        }

        function selectFile(size) {
            const file = new File(['x'], 'backup.zip');
            Object.defineProperty(file, 'size', { value: size });
            Object.defineProperty(document.getElementById('restore-backup-file'), 'files', {
                configurable: true, value: [file],
            });
        }

        function submitForm() {
            const evt = new Event('submit', { cancelable: true });
            document.getElementById('restore-backup-form').dispatchEvent(evt);
            return evt;
        }

        it('intercepts the submit for a large file: chunks first, then re-submits with upload_id and no file', async () => {
            buildChunkedRestore();
            selectFile(100 * 1024 * 1024);
            const nativeSubmit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
            let resolveUpload;
            window.ScoutMagicChunkedUpload = {
                CHUNK_THRESHOLD: 24 * 1024 * 1024,
                uploadInChunks: vi.fn(() => new Promise((resolve) => { resolveUpload = resolve; })),
            };
            await boot();

            const evt = submitForm();
            await vi.waitFor(() => expect(window.ScoutMagicChunkedUpload.uploadInChunks).toHaveBeenCalled());

            expect(evt.defaultPrevented).toBe(true);
            expect(window.ScoutMagicChunkedUpload.uploadInChunks).toHaveBeenCalledWith(
                expect.anything(), '/config/maintenance/restore-upload-chunk',
                // The chunker's token now comes from ScoutMagicApi.csrfToken()
                // (the page-level meta tag), not the form's hidden input.
                expect.objectContaining({ csrfToken: 'tok' }),
            );
            expect(document.getElementById('restore-backup-progress').classList.contains('d-none')).toBe(false);

            resolveUpload({ uploadId: 'f'.repeat(32), data: { success: true } });
            await Promise.resolve(); await Promise.resolve();

            expect(document.getElementById('restore-upload-id').value).toBe('f'.repeat(32));
            expect(nativeSubmit).toHaveBeenCalledTimes(1);
            // .submit() bypasses the listener — one confirmation, one chunk pass.
            expect(window.ScoutMagicConfirm.ask).toHaveBeenCalledTimes(1);
        });

        it('lets a small file ride the classic multipart POST untouched', async () => {
            buildChunkedRestore();
            selectFile(1024);
            const nativeSubmit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
            window.ScoutMagicChunkedUpload = {
                CHUNK_THRESHOLD: 24 * 1024 * 1024,
                uploadInChunks: vi.fn(),
            };
            await boot();

            submitForm();
            await vi.waitFor(() => expect(nativeSubmit).toHaveBeenCalledTimes(1));

            expect(window.ScoutMagicChunkedUpload.uploadInChunks).not.toHaveBeenCalled();
        });

        it('does not chunk when the source is a server-side backup, whatever the picked file', async () => {
            buildChunkedRestore();
            selectFile(100 * 1024 * 1024);
            document.getElementById('restore-source-upload').checked = false;
            document.getElementById('restore-source-server').checked = true;
            const nativeSubmit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
            window.ScoutMagicChunkedUpload = {
                CHUNK_THRESHOLD: 24 * 1024 * 1024,
                uploadInChunks: vi.fn(),
            };
            await boot();

            submitForm();
            await vi.waitFor(() => expect(nativeSubmit).toHaveBeenCalledTimes(1));

            expect(window.ScoutMagicChunkedUpload.uploadInChunks).not.toHaveBeenCalled();
        });

        it('shows the error and re-enables the button when chunking fails', async () => {
            buildChunkedRestore();
            selectFile(100 * 1024 * 1024);
            window.ScoutMagicChunkedUpload = {
                CHUNK_THRESHOLD: 24 * 1024 * 1024,
                uploadInChunks: vi.fn(() => Promise.reject(new Error('Le fichier dépasse la taille maximale autorisée (500 Mo).'))),
            };
            await boot();

            submitForm();
            const errorEl = document.getElementById('restore-backup-error');
            await vi.waitFor(() => expect(errorEl.classList.contains('d-none')).toBe(false));

            expect(errorEl.textContent).toBe('Le fichier dépasse la taille maximale autorisée (500 Mo).');
            expect(/** @type {HTMLButtonElement} */ (document.getElementById('restore-backup-submit')).disabled).toBe(false);
            expect(document.getElementById('restore-backup-progress').classList.contains('d-none')).toBe(true);
        });
    });

    describe('resume polling after a restore redirect (?restore_id=N in the URL)', () => {
        it('does nothing when the URL carries no restore_id', async () => {
            global.fetch = vi.fn();
            Object.defineProperty(window, 'location', { configurable: true, value: { search: '' } });
            await boot();
            expect(fetch).not.toHaveBeenCalled();
        });

        it('immediately starts polling reset-status for the id in the URL, with no form submit needed', async () => {
            appendAll(
                el('<div id="restore-backup-progress" class="d-none"></div>'),
                el('<div id="restore-backup-error" class="d-none"></div>'),
            );
            Object.defineProperty(window, 'location', { configurable: true, value: { search: '?restore_id=99', href: '' } });
            global.fetch = vi.fn()
                .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'done' }) });
            vi.useFakeTimers();
            await boot();
            expect(document.getElementById('restore-backup-progress').classList.contains('d-none')).toBe(false);
            await vi.advanceTimersByTimeAsync(3000);
            expect(fetch).toHaveBeenCalledWith('/api/maintenance/reset-status/99', expect.anything());
            expect(window.location.href).toBe('/config/maintenance');
        });

        it('shows the failure message on the resumed poll without redirecting', async () => {
            appendAll(
                el('<div id="restore-backup-progress" class="d-none"></div>'),
                el('<div id="restore-backup-error" class="d-none"></div>'),
            );
            Object.defineProperty(window, 'location', { configurable: true, value: { search: '?restore_id=99', href: '' } });
            global.fetch = vi.fn()
                .mockResolvedValueOnce({ json: () => Promise.resolve({ status: 'failed', error_message: 'Archive corrompue.' }) });
            vi.useFakeTimers();
            await boot();
            await vi.advanceTimersByTimeAsync(3000);
            expect(document.getElementById('restore-backup-error').textContent).toBe('Archive corrompue.');
            expect(document.getElementById('restore-backup-progress').classList.contains('d-none')).toBe(true);
            expect(window.location.href).toBe('');
        });
    });
});
