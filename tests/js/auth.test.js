// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked, and so is navigator.credentials
// for the passkey flow. Exercises the REAL implementation in
// public/assets/js/auth.js (imported below, never reimplemented here).
//
// auth.js has no guard on most of its DOM lookups (unlike most files
// covered so far) — sendBtn.addEventListener() at import time throws on a
// bare document, confirmed earlier in docs/js-test-coverage-plan.md's
// §4.3. So every test here builds the required DOM BEFORE importing, via
// vi.resetModules() + a dynamic import, same as tests/js/settings.test.js.
//
// base64ToBuffer()/bufferToBase64() (the WebAuthn credential encoding) are
// reached through the namespaced globalThis.ScoutMagicAuthInternals test
// seam this file exposes for exactly this purpose — see the comment at
// the bottom of it — since their edge cases (padding, empty buffers,
// URL-safe substitution) are worth exercising directly and exhaustively,
// not only through a fully mocked passkey round-trip.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function el(html) {
    const div = document.createElement('div');
    div.innerHTML = html.trim();
    return div.firstElementChild;
}

function appendAll(...nodes) { nodes.forEach((n) => document.body.appendChild(n)); }

// The magic-link flow (tabs, sendBtn, backBtn, emailInput, the three
// state-* panels) has NO `if (!x) return` guard anywhere, so all of it is
// required on every single test regardless of which feature is under
// test. password-login/forgot-password/passkey are each independently
// optional (their own `if (x)` guard), added only when a test needs them.
function buildCoreDom() {
    appendAll(
        el('<input type="hidden" id="csrf-token" value="tok">'),
        el('<button data-tab="magic-link" class="active"></button>'),
        el('<button data-tab="password"></button>'),
        el('<div id="tab-magic-link"></div>'),
        el('<div id="tab-password" class="d-none"></div>'),
        el('<div id="state-email"></div>'),
        el('<div id="state-waiting" class="d-none"></div>'),
        el('<div id="state-confirmed" class="d-none"></div>'),
        el('<button id="send-magic-link">Envoyer le lien de connexion</button>'),
        el('<button id="back-btn"></button>'),
        el('<input id="email">'),
        el('<div id="email-error" class="d-none"></div>'),
        el('<span id="sent-email"></span>'),
        el('<input type="checkbox" id="rgpd-consent-checkbox-magic-link" checked>'),
        el('<div id="rgpd-consent-error-magic-link" class="d-none"></div>'),
    );
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/auth.js');
}

let trackedListeners = [];
function trackListeners(target) {
    const original = target.addEventListener.bind(target);
    target.addEventListener = (type, listener, options) => {
        trackedListeners.push({ target, type, listener, options });
        return original(type, listener, options);
    };
}

beforeEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
    document.body.innerHTML = '';
    // auth.js registers document-level listeners (visibilitychange for the
    // magic-link poll resume) in addition to per-element ones — same
    // leakage risk found and fixed in tests/js/offline-nav.test.js.
    trackedListeners = [];
    trackListeners(document);
    trackListeners(window);
});

afterEach(() => {
    trackedListeners.forEach(({ target, type, listener, options }) => {
        target.removeEventListener(type, listener, options);
    });
});

describe('auth.js: base64url helpers (WebAuthn credential encoding)', () => {
    it('round-trips arbitrary bytes through bufferToBase64 -> base64ToBuffer', async () => {
        buildCoreDom();
        await boot();
        const auth = globalThis.ScoutMagicAuthInternals;
        const original = new Uint8Array([0, 1, 2, 253, 254, 255, 127, 128]);
        const encoded = auth.bufferToBase64(original.buffer);
        const decoded = new Uint8Array(auth.base64ToBuffer(encoded));
        expect(Array.from(decoded)).toEqual(Array.from(original));
    });

    it('produces URL-safe output — no +, / or = padding characters', async () => {
        buildCoreDom();
        await boot();
        const auth = globalThis.ScoutMagicAuthInternals;
        // 1 and 2-byte buffers are exactly the lengths that require base64
        // padding, which is what most often gets forgotten in a
        // hand-rolled base64url implementation.
        [new Uint8Array([255]), new Uint8Array([255, 254]), new Uint8Array([255, 254, 253])].forEach((bytes) => {
            const encoded = auth.bufferToBase64(bytes.buffer);
            expect(encoded).not.toMatch(/[+/=]/);
        });
    });

    it('decodes a server-supplied base64url string containing - and _ substitutions', async () => {
        buildCoreDom();
        await boot();
        const auth = globalThis.ScoutMagicAuthInternals;
        // Standard base64 of these bytes contains both '+' and '/', which
        // base64url spells as '-' and '_' — proves the substitution is
        // reversed on decode, not just applied on encode.
        const bytes = new Uint8Array([251, 255, 191]); // base64: +/+/
        const urlSafe = auth.bufferToBase64(bytes.buffer);
        expect(urlSafe).toBe('-_-_');
        expect(Array.from(new Uint8Array(auth.base64ToBuffer(urlSafe)))).toEqual(Array.from(bytes));
    });

    it('round-trips an empty buffer', async () => {
        buildCoreDom();
        await boot();
        const auth = globalThis.ScoutMagicAuthInternals;
        expect(auth.bufferToBase64(new ArrayBuffer(0))).toBe('');
        expect(new Uint8Array(auth.base64ToBuffer('')).length).toBe(0);
    });
});

describe('auth.js: tab switching', () => {
    it('activates the clicked tab and shows only its content panel', async () => {
        buildCoreDom();
        await boot();
        document.querySelector('[data-tab="password"]').dispatchEvent(new Event('click'));

        expect(document.querySelector('[data-tab="password"]').classList.contains('active')).toBe(true);
        expect(document.querySelector('[data-tab="magic-link"]').classList.contains('active')).toBe(false);
        expect(document.getElementById('tab-password').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('tab-magic-link').classList.contains('d-none')).toBe(true);
    });
});

describe('auth.js: RGPD consent gate (magic-link) — a real compliance boundary', () => {
    it('blocks the request entirely and shows the consent error when unchecked', async () => {
        buildCoreDom();
        document.getElementById('rgpd-consent-checkbox-magic-link').checked = false;
        /** @type {HTMLInputElement} */ (document.getElementById('email')).value = 'a@b.test';
        global.fetch = vi.fn();
        await boot();

        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));

        expect(fetch).not.toHaveBeenCalled();
        expect(document.getElementById('rgpd-consent-error-magic-link').classList.contains('d-none')).toBe(false);
    });

    it('proceeds and hides the consent error once checked', async () => {
        buildCoreDom();
        /** @type {HTMLInputElement} */ (document.getElementById('email')).value = 'a@b.test';
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false, error: 'x' }) }));
        await boot();

        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(document.getElementById('rgpd-consent-error-magic-link').classList.contains('d-none')).toBe(true);
    });
});

describe('auth.js: magic-link send', () => {
    async function ready(email = 'a@b.test') {
        buildCoreDom();
        /** @type {HTMLInputElement} */ (document.getElementById('email')).value = email;
        await boot();
    }

    it('shows an error and never calls fetch when the email is empty', async () => {
        global.fetch = vi.fn();
        await ready('');
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));
        expect(fetch).not.toHaveBeenCalled();
        expect(document.getElementById('email-error').textContent).toBe('Veuillez entrer une adresse email.');
        expect(document.getElementById('email-error').classList.contains('d-none')).toBe(false);
    });

    it('trims whitespace before checking for emptiness', async () => {
        global.fetch = vi.fn();
        await ready('   ');
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));
        expect(fetch).not.toHaveBeenCalled();
    });

    it('POSTs the trimmed email, CSRF token and rgpd_consent as a urlencoded body', async () => {
        global.fetch = vi.fn(() => new Promise(() => {}));
        await ready('  a@b.test  ');
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));

        expect(fetch).toHaveBeenCalledWith('/login/magic-link', expect.objectContaining({
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'email=a%40b.test&_csrf_token=tok&rgpd_consent=1',
        }));
    });

    // humanCheckParams(): appends the human-check token and honeypot trap
    // field, in that order, ONLY when each is present in the DOM — a page
    // that renders neither (e.g. a trusted context) sends neither.
    it('appends the human-check token when present', async () => {
        buildCoreDom();
        appendAll(el('<input name="human_check_token" value="hc123">'));
        /** @type {HTMLInputElement} */ (document.getElementById('email')).value = 'a@b.test';
        global.fetch = vi.fn(() => new Promise(() => {}));
        await boot();
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));

        expect(fetch).toHaveBeenCalledWith('/login/magic-link', expect.objectContaining({
            body: expect.stringContaining('&human_check_token=hc123'),
        }));
    });

    it('appends the honeypot trap field name/value when present', async () => {
        buildCoreDom();
        appendAll(el('<div class="hc-trap"><input name="website" value=""></div>'));
        /** @type {HTMLInputElement} */ (document.getElementById('email')).value = 'a@b.test';
        global.fetch = vi.fn(() => new Promise(() => {}));
        await boot();
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));

        expect(fetch).toHaveBeenCalledWith('/login/magic-link', expect.objectContaining({
            body: expect.stringContaining('&website='),
        }));
    });

    it('sends neither human-check param when neither element is present', async () => {
        global.fetch = vi.fn(() => new Promise(() => {}));
        await ready();
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));

        expect(fetch).toHaveBeenCalledWith('/login/magic-link', expect.objectContaining({
            body: 'email=a%40b.test&_csrf_token=tok&rgpd_consent=1',
        }));
    });

    it('disables the button and changes its label while the request is in flight, restoring both on response', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false, error: 'x' }) }));
        await ready();
        const btn = /** @type {HTMLButtonElement} */ (document.getElementById('send-magic-link'));

        btn.dispatchEvent(new Event('click'));
        expect(btn.disabled).toBe(true);
        expect(btn.textContent).toBe('Envoi en cours…');

        await vi.waitFor(() => expect(btn.disabled).toBe(false));
        expect(btn.textContent).toBe('Envoyer le lien de connexion');
    });

    it('on success, shows the waiting state with the sent email and starts polling', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true, poll_id: 42 }) }));
        await ready('a@b.test');
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(document.getElementById('state-waiting').classList.contains('d-none')).toBe(false));
        expect(document.getElementById('state-email').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('sent-email').textContent).toBe('a@b.test');
    });

    it('shows the server error message on a rejected request', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false, error: 'Compte introuvable.' }) }));
        await ready();
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('email-error').textContent).toBe('Compte introuvable.'));
    });

    it('shows a generic network-error message on a fetch rejection', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await ready();
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('email-error').textContent).toBe('Erreur réseau. Veuillez réessayer.'));
    });

    it('the back button stops polling and returns to the email state', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true, poll_id: 42 }) }));
        await ready('a@b.test');
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('state-waiting').classList.contains('d-none')).toBe(false));

        document.getElementById('back-btn').dispatchEvent(new Event('click'));

        expect(document.getElementById('state-email').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('state-waiting').classList.contains('d-none')).toBe(true);
    });

    it('Enter in the email field submits the same way as clicking the button', async () => {
        global.fetch = vi.fn(() => new Promise(() => {}));
        await ready('a@b.test');
        const evt = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true });
        document.getElementById('email').dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(true);
        expect(fetch).toHaveBeenCalledWith('/login/magic-link', expect.anything());
    });

    it('a non-Enter key does nothing', async () => {
        global.fetch = vi.fn();
        await ready('a@b.test');
        document.getElementById('email').dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));
        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('auth.js: magic-link polling', () => {
    async function startPolling() {
        buildCoreDom();
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true, poll_id: 42 }) });
        /** @type {HTMLInputElement} */ (document.getElementById('email')).value = 'a@b.test';
        vi.useFakeTimers();
        await boot();
        document.getElementById('send-magic-link').dispatchEvent(new Event('click'));
        await vi.advanceTimersByTimeAsync(0); // let the launch promise resolve
    }

    it('polls /auth/poll/{id} every 3 seconds', async () => {
        await startPolling();
        global.fetch.mockResolvedValue({ json: () => Promise.resolve({ confirmed: false }) });

        await vi.advanceTimersByTimeAsync(3000);
        expect(fetch).toHaveBeenCalledWith('/auth/poll/42');
        const countAfterOne = fetch.mock.calls.length;
        await vi.advanceTimersByTimeAsync(3000);
        expect(fetch.mock.calls.length).toBeGreaterThan(countAfterOne);
    });

    it('on confirmed, shows the confirmed state, stops polling, and redirects after 2s', async () => {
        await startPolling();
        global.fetch.mockResolvedValue({ json: () => Promise.resolve({ confirmed: true }) });
        Object.defineProperty(window, 'location', { configurable: true, value: { href: '' } });

        await vi.advanceTimersByTimeAsync(3000);
        expect(document.getElementById('state-confirmed').classList.contains('d-none')).toBe(false);
        // The 2s redirect timer is already ticking at this point (started
        // the instant "confirmed" was detected, in the same tick above),
        // so this must be checked BEFORE any further time advances, not
        // after — the "no further polling" check below advances a full
        // 3000ms, which would blow straight past the 2000ms redirect too.
        expect(window.location.href).toBe('');

        const countAtConfirm = fetch.mock.calls.length;
        await vi.advanceTimersByTimeAsync(1999); // just short of the redirect
        expect(fetch.mock.calls.length).toBe(countAtConfirm); // no further polling
        expect(window.location.href).toBe('');

        await vi.advanceTimersByTimeAsync(1);
        expect(window.location.href).toBe('/');
    });

    it('a transient poll network error is swallowed and polling continues', async () => {
        await startPolling();
        global.fetch
            .mockRejectedValueOnce(new Error('blip'))
            .mockResolvedValueOnce({ json: () => Promise.resolve({ confirmed: true }) });
        Object.defineProperty(window, 'location', { configurable: true, value: { href: '' } });

        await vi.advanceTimersByTimeAsync(3000); // network error tick
        await vi.advanceTimersByTimeAsync(3000); // confirmed tick
        expect(document.getElementById('state-confirmed').classList.contains('d-none')).toBe(false);
    });

    // The reason this handler exists at all: backgrounded tabs (especially
    // an installed PWA) can have setInterval throttled or fully suspended,
    // so returning to the tab must check immediately rather than wait for
    // the next regular tick.
    it('checks immediately when the tab becomes visible again, ahead of the next 3s tick', async () => {
        await startPolling();
        global.fetch.mockResolvedValue({ json: () => Promise.resolve({ confirmed: false }) });
        const countBefore = fetch.mock.calls.length;

        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.waitFor(() => expect(fetch.mock.calls.length).toBeGreaterThan(countBefore));
    });

    it('does NOT check when the visibilitychange fires with the tab going hidden', async () => {
        await startPolling();
        global.fetch.mockResolvedValue({ json: () => Promise.resolve({ confirmed: false }) });
        const countBefore = fetch.mock.calls.length;

        Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));

        expect(fetch.mock.calls.length).toBe(countBefore);
    });

    it('gives up after 15 minutes even if never confirmed', async () => {
        await startPolling();
        global.fetch.mockResolvedValue({ json: () => Promise.resolve({ confirmed: false }) });

        await vi.advanceTimersByTimeAsync(15 * 60 * 1000);
        const countAtGiveUp = fetch.mock.calls.length;
        await vi.advanceTimersByTimeAsync(3000);
        expect(fetch.mock.calls.length).toBe(countAtGiveUp); // no further polling
    });

    it('a stale in-flight poll response arriving after stopPolling() must not act on it', async () => {
        // pollingInterval is checked at the callback's OWN completion, not
        // just before the fetch — this guards a response that resolves
        // after the user already clicked "back" while the request was in
        // flight.
        await startPolling();
        let resolvePoll;
        global.fetch.mockImplementation(() => new Promise((resolve) => { resolvePoll = resolve; }));
        Object.defineProperty(window, 'location', { configurable: true, value: { href: '' } });

        await vi.advanceTimersByTimeAsync(3000); // poll request now in flight
        document.getElementById('back-btn').dispatchEvent(new Event('click')); // stopPolling() runs
        resolvePoll({ json: () => Promise.resolve({ confirmed: true }) });
        await vi.advanceTimersByTimeAsync(0);

        expect(document.getElementById('state-confirmed').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('state-email').classList.contains('d-none')).toBe(false);
    });
});

describe('auth.js: password login', () => {
    function buildPasswordDom() {
        appendAll(
            el('<button id="password-login-btn"></button>'),
            el('<input id="password-email">'),
            el('<input id="password-input" type="password">'),
            el('<div id="password-error" class="d-none"></div>'),
            el('<div id="password-lockout" class="d-none"></div>'),
            el('<input type="checkbox" id="rgpd-consent-checkbox-password" checked>'),
            el('<div id="rgpd-consent-error-password" class="d-none"></div>'),
        );
    }

    async function ready(email = 'a@b.test', password = 'pw') {
        buildCoreDom();
        buildPasswordDom();
        /** @type {HTMLInputElement} */ (document.getElementById('password-email')).value = email;
        /** @type {HTMLInputElement} */ (document.getElementById('password-input')).value = password;
        await boot();
    }

    it('is not wired at all when the password tab does not exist on the page', async () => {
        buildCoreDom();
        global.fetch = vi.fn();
        await boot();
        expect(document.getElementById('password-login-btn')).toBeNull();
    });

    it('blocks the request and shows the consent error when RGPD is unchecked', async () => {
        await ready();
        document.getElementById('rgpd-consent-checkbox-password').checked = false;
        global.fetch = vi.fn();
        document.getElementById('password-login-btn').dispatchEvent(new Event('click'));
        await Promise.resolve();
        expect(fetch).not.toHaveBeenCalled();
        expect(document.getElementById('rgpd-consent-error-password').classList.contains('d-none')).toBe(false);
    });

    it('shows a validation error and never calls fetch when either field is empty', async () => {
        await ready('', 'pw');
        global.fetch = vi.fn();
        document.getElementById('password-login-btn').dispatchEvent(new Event('click'));
        await Promise.resolve();
        expect(fetch).not.toHaveBeenCalled();
        expect(document.getElementById('password-error').textContent).toBe('Veuillez remplir tous les champs.');
    });

    it('POSTs email/password/csrf/rgpd_consent as JSON', async () => {
        await ready('a@b.test', 's3cret');
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({}) }));
        document.getElementById('password-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/login/password', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ email: 'a@b.test', password: 's3cret', _csrf_token: 'tok', rgpd_consent: true }),
        })));
    });

    it('redirects to "/" on success', async () => {
        await ready();
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
        Object.defineProperty(window, 'location', { configurable: true, value: { href: '' } });
        document.getElementById('password-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(window.location.href).toBe('/'));
    });

    it('shows the lockout message, rounding seconds up to whole minutes', async () => {
        await ready();
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false, locked_seconds: 61 }) }));
        document.getElementById('password-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('password-lockout').classList.contains('d-none')).toBe(false));
        expect(document.getElementById('password-lockout').textContent).toBe('Trop de tentatives. Réessayez dans 2 minute(s).');
        expect(document.getElementById('password-error').classList.contains('d-none')).toBe(true);
    });

    it('a lockout of exactly one minute does not round up to two', async () => {
        await ready();
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false, locked_seconds: 60 }) }));
        document.getElementById('password-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('password-lockout').textContent).toBe('Trop de tentatives. Réessayez dans 1 minute(s).'));
    });

    it('shows the generic invalid-credentials error when not locked and no specific error given', async () => {
        await ready();
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
        document.getElementById('password-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('password-error').textContent).toBe('Identifiants invalides.'));
        expect(document.getElementById('password-lockout').classList.contains('d-none')).toBe(true);
    });

    it('shows a network-error message when the request itself throws', async () => {
        await ready();
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        document.getElementById('password-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('password-error').textContent).toBe('Erreur réseau. Veuillez réessayer.'));
    });

    it('Enter in the password field submits the same way as clicking the button', async () => {
        await ready();
        global.fetch = vi.fn(() => new Promise(() => {}));
        const evt = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true });
        document.getElementById('password-input').dispatchEvent(evt);
        expect(evt.defaultPrevented).toBe(true);
        expect(fetch).toHaveBeenCalledWith('/login/password', expect.anything());
    });
});

describe('auth.js: "Mot de passe oublié ?"', () => {
    function buildForgotDom() {
        appendAll(
            el('<a id="forgot-password-link" href="#"></a>'),
            el('<div id="forgot-password-form" class="d-none"></div>'),
            el('<input id="forgot-password-email">'),
            el('<button id="forgot-password-submit-btn"></button>'),
            el('<div id="forgot-password-message" class="d-none"></div>'),
        );
    }

    it('is not wired when either the link or the form is missing', async () => {
        buildCoreDom();
        appendAll(el('<a id="forgot-password-link" href="#"></a>')); // form missing
        global.fetch = vi.fn();
        await boot();
        document.getElementById('forgot-password-link').dispatchEvent(new Event('click', { cancelable: true }));
        expect(fetch).not.toHaveBeenCalled();
    });

    it('toggling the link shows the form and prefills from the password tab email, then focuses it', async () => {
        buildCoreDom();
        buildForgotDom();
        appendAll(el('<input id="password-email" value="a@b.test">'));
        await boot();

        const evt = new Event('click', { cancelable: true });
        document.getElementById('forgot-password-link').dispatchEvent(evt);

        expect(evt.defaultPrevented).toBe(true);
        expect(document.getElementById('forgot-password-form').classList.contains('d-none')).toBe(false);
        expect(/** @type {HTMLInputElement} */ (document.getElementById('forgot-password-email')).value).toBe('a@b.test');
        expect(document.activeElement).toBe(document.getElementById('forgot-password-email'));
    });

    it('does not overwrite a manually-typed email with an empty prefill', async () => {
        // document.getElementById('password-email').value has NO null
        // guard in the source, so that element always exists wherever the
        // forgot-password link does (same page, same "password" tab) — an
        // EMPTY value, not a missing element, is the real "nothing to
        // prefill" case.
        buildCoreDom();
        buildForgotDom();
        appendAll(el('<input id="password-email" value="">'));
        /** @type {HTMLInputElement} */ (document.getElementById('forgot-password-email')).value = 'kept@b.test';
        await boot();
        document.getElementById('forgot-password-link').dispatchEvent(new Event('click', { cancelable: true }));
        expect(/** @type {HTMLInputElement} */ (document.getElementById('forgot-password-email')).value).toBe('kept@b.test');
    });

    it('clicking the link again toggles the form closed', async () => {
        buildCoreDom();
        buildForgotDom();
        appendAll(el('<input id="password-email" value="">'));
        await boot();
        const link = document.getElementById('forgot-password-link');
        link.dispatchEvent(new Event('click', { cancelable: true }));
        link.dispatchEvent(new Event('click', { cancelable: true }));
        expect(document.getElementById('forgot-password-form').classList.contains('d-none')).toBe(true);
    });

    it('does nothing and never calls fetch when the email field is empty', async () => {
        buildCoreDom();
        buildForgotDom();
        global.fetch = vi.fn();
        await boot();
        document.getElementById('forgot-password-submit-btn').dispatchEvent(new Event('click'));
        await Promise.resolve();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('POSTs the trimmed email and CSRF token, urlencoded', async () => {
        buildCoreDom();
        buildForgotDom();
        /** @type {HTMLInputElement} */ (document.getElementById('forgot-password-email')).value = '  a@b.test  ';
        global.fetch = vi.fn(() => new Promise(() => {}));
        await boot();
        document.getElementById('forgot-password-submit-btn').dispatchEvent(new Event('click'));

        expect(fetch).toHaveBeenCalledWith('/password-reset/request', expect.objectContaining({
            body: 'email=a%40b.test&_csrf_token=tok',
        }));
    });

    it('disables the button while in flight and re-enables it afterwards (finally), on success', async () => {
        buildCoreDom();
        buildForgotDom();
        /** @type {HTMLInputElement} */ (document.getElementById('forgot-password-email')).value = 'a@b.test';
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
        await boot();
        const btn = /** @type {HTMLButtonElement} */ (document.getElementById('forgot-password-submit-btn'));

        btn.dispatchEvent(new Event('click'));
        expect(btn.disabled).toBe(true);

        await vi.waitFor(() => expect(btn.disabled).toBe(false));
        expect(document.getElementById('forgot-password-message').classList.contains('text-success')).toBe(true);
        expect(document.getElementById('forgot-password-message').classList.contains('text-danger')).toBe(false);
    });

    it('re-enables the button (finally) even when the launch itself fails', async () => {
        buildCoreDom();
        buildForgotDom();
        /** @type {HTMLInputElement} */ (document.getElementById('forgot-password-email')).value = 'a@b.test';
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false, error: 'Trop de demandes.' }) }));
        await boot();
        const btn = /** @type {HTMLButtonElement} */ (document.getElementById('forgot-password-submit-btn'));
        btn.dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(btn.disabled).toBe(false));
        expect(document.getElementById('forgot-password-message').textContent).toBe('Trop de demandes.');
        expect(document.getElementById('forgot-password-message').classList.contains('text-danger')).toBe(true);
    });

    it('re-enables the button (finally) on a network failure too', async () => {
        buildCoreDom();
        buildForgotDom();
        /** @type {HTMLInputElement} */ (document.getElementById('forgot-password-email')).value = 'a@b.test';
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();
        const btn = /** @type {HTMLButtonElement} */ (document.getElementById('forgot-password-submit-btn'));
        btn.dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(btn.disabled).toBe(false));
        expect(document.getElementById('forgot-password-message').textContent).toBe('Erreur réseau. Veuillez réessayer.');
    });
});

describe('auth.js: passkey login', () => {
    function buildPasskeyDom() {
        appendAll(
            el('<button id="passkey-login-btn"></button>'),
            el('<div id="passkey-error" class="d-none"></div>'),
            el('<div id="passkey-unsupported" class="d-none"></div>'),
            el('<input type="checkbox" id="rgpd-consent-checkbox-passkey" checked>'),
            el('<div id="rgpd-consent-error-passkey" class="d-none"></div>'),
        );
    }

    it('is not wired at all when the passkey tab does not exist on the page', async () => {
        buildCoreDom();
        await boot();
        expect(document.getElementById('passkey-login-btn')).toBeNull();
    });

    it('disables the button and shows the "unsupported" message when PublicKeyCredential is absent', async () => {
        buildCoreDom();
        buildPasskeyDom();
        delete window.PublicKeyCredential;
        await boot();
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('passkey-login-btn')).disabled).toBe(true);
        expect(document.getElementById('passkey-unsupported').classList.contains('d-none')).toBe(false);
    });

    it('leaves the button enabled when PublicKeyCredential is present', async () => {
        buildCoreDom();
        buildPasskeyDom();
        window.PublicKeyCredential = function () {};
        await boot();
        expect(/** @type {HTMLButtonElement} */ (document.getElementById('passkey-login-btn')).disabled).toBe(false);
        expect(document.getElementById('passkey-unsupported').classList.contains('d-none')).toBe(true);
    });

    it('blocks the attempt and shows the consent error when RGPD is unchecked', async () => {
        buildCoreDom();
        buildPasskeyDom();
        window.PublicKeyCredential = function () {};
        document.getElementById('rgpd-consent-checkbox-passkey').checked = false;
        global.fetch = vi.fn();
        await boot();
        document.getElementById('passkey-login-btn').dispatchEvent(new Event('click'));
        await Promise.resolve();
        expect(fetch).not.toHaveBeenCalled();
        expect(document.getElementById('rgpd-consent-error-passkey').classList.contains('d-none')).toBe(false);
    });

    it('decodes the challenge and every allowCredentials id from base64url before calling navigator.credentials.get', async () => {
        buildCoreDom();
        buildPasskeyDom();
        window.PublicKeyCredential = function () {};
        const auth = null; // seam not needed here — asserting on the call args is the real contract
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({
                challenge: 'AQID', // bytes [1,2,3]
                allowCredentials: [{ id: 'AQID', type: 'public-key' }],
            }) });
        const getSpy = vi.fn(() => new Promise(() => {}));
        Object.defineProperty(navigator, 'credentials', { configurable: true, value: { get: getSpy } });
        await boot();

        document.getElementById('passkey-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(getSpy).toHaveBeenCalled());

        const optionsArg = getSpy.mock.calls[0][0].publicKey;
        expect(Array.from(new Uint8Array(optionsArg.challenge))).toEqual([1, 2, 3]);
        expect(Array.from(new Uint8Array(optionsArg.allowCredentials[0].id))).toEqual([1, 2, 3]);
        expect(optionsArg.allowCredentials[0].type).toBe('public-key');
        void auth;
    });

    it('handles a response with no allowCredentials at all', async () => {
        buildCoreDom();
        buildPasskeyDom();
        window.PublicKeyCredential = function () {};
        global.fetch = vi.fn().mockResolvedValueOnce({ json: () => Promise.resolve({ challenge: 'AQID' }) });
        Object.defineProperty(navigator, 'credentials', { configurable: true, value: { get: vi.fn(() => new Promise(() => {})) } });
        await boot();
        expect(() => document.getElementById('passkey-login-btn').dispatchEvent(new Event('click'))).not.toThrow();
    });

    it('encodes the full assertion response to base64url and POSTs it, on a successful get()', async () => {
        buildCoreDom();
        buildPasskeyDom();
        window.PublicKeyCredential = function () {};
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ challenge: 'AQID' }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true }) });
        const fakeCredential = {
            id: 'cred-id', type: 'public-key',
            rawId: new Uint8Array([9, 9]).buffer,
            response: {
                authenticatorData: new Uint8Array([1]).buffer,
                clientDataJSON: new Uint8Array([2]).buffer,
                signature: new Uint8Array([3]).buffer,
                userHandle: new Uint8Array([4]).buffer,
            },
        };
        Object.defineProperty(navigator, 'credentials', { configurable: true, value: { get: vi.fn(() => Promise.resolve(fakeCredential)) } });
        Object.defineProperty(window, 'location', { configurable: true, value: { href: '' } });
        await boot();

        document.getElementById('passkey-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(window.location.href).toBe('/'));

        expect(fetch).toHaveBeenNthCalledWith(2, '/login/passkey/verify', expect.objectContaining({
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': 'tok' },
        }));
        const body = JSON.parse(fetch.mock.calls[1][1].body);
        expect(body).toMatchObject({ id: 'cred-id', type: 'public-key', rgpd_consent: true });
        expect(body.rawId).toBe('CQk'); // bytes [9,9] base64url, no padding
        expect(body.response.userHandle).not.toBeNull();
    });

    it('encodes a null userHandle as null, not an empty/garbage string', async () => {
        buildCoreDom();
        buildPasskeyDom();
        window.PublicKeyCredential = function () {};
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ challenge: 'AQID' }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true }) });
        const fakeCredential = {
            id: 'cred-id', type: 'public-key', rawId: new Uint8Array([9]).buffer,
            response: {
                authenticatorData: new Uint8Array([1]).buffer,
                clientDataJSON: new Uint8Array([2]).buffer,
                signature: new Uint8Array([3]).buffer,
                userHandle: null,
            },
        };
        Object.defineProperty(navigator, 'credentials', { configurable: true, value: { get: vi.fn(() => Promise.resolve(fakeCredential)) } });
        Object.defineProperty(window, 'location', { configurable: true, value: { href: '' } });
        await boot();
        document.getElementById('passkey-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
        expect(JSON.parse(fetch.mock.calls[1][1].body).response.userHandle).toBeNull();
    });

    it('shows the server error when verification is rejected', async () => {
        buildCoreDom();
        buildPasskeyDom();
        window.PublicKeyCredential = function () {};
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ json: () => Promise.resolve({ challenge: 'AQID' }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: false, error: 'Clé inconnue.' }) });
        const fakeCredential = {
            id: 'x', type: 'public-key', rawId: new Uint8Array([1]).buffer,
            response: { authenticatorData: new Uint8Array([1]).buffer, clientDataJSON: new Uint8Array([1]).buffer, signature: new Uint8Array([1]).buffer, userHandle: null },
        };
        Object.defineProperty(navigator, 'credentials', { configurable: true, value: { get: vi.fn(() => Promise.resolve(fakeCredential)) } });
        await boot();
        document.getElementById('passkey-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('passkey-error').textContent).toBe('Clé inconnue.'));
    });

    it('shows a cancellation/failure message when the browser prompt itself rejects', async () => {
        buildCoreDom();
        buildPasskeyDom();
        window.PublicKeyCredential = function () {};
        global.fetch = vi.fn().mockResolvedValueOnce({ json: () => Promise.resolve({ challenge: 'AQID' }) });
        Object.defineProperty(navigator, 'credentials', { configurable: true, value: { get: vi.fn(() => Promise.reject(new Error('NotAllowedError'))) } });
        await boot();
        document.getElementById('passkey-login-btn').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(document.getElementById('passkey-error').textContent).toBe("L'authentification a été annulée ou a échoué."));
    });
});
