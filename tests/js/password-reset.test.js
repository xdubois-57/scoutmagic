// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, and so is
// history.replaceState. Exercises the REAL implementation in
// public/assets/js/password-reset.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM and the
// URL fragment at import time, so each test sets both up first and then
// imports the module via vi.resetModules() + await import().
//
// This is the file that decides whether someone can set a new password,
// and until it moved out of the template it had no test at all.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const PAGE = `
    <div class="card" id="reset-checking"><p>Vérification du lien…</p></div>
    <div class="card d-none" id="reset-invalid"><p>Ce lien n'est plus valide.</p></div>
    <div class="card d-none" id="reset-panel">
        <form method="post" action="/password-reset/17" id="reset-form">
            <input type="hidden" name="_csrf_token" value="tok-123">
            <input type="hidden" name="token" value="" id="reset-token">
        </form>
    </div>`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('password-reset.js', () => {
    let replaceState;

    beforeEach(() => {
        vi.resetModules();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE;
        global.fetch = vi.fn(() => jsonResponse({ valid: true }));
        replaceState = vi.fn();
        Object.defineProperty(window, 'history', {
            configurable: true,
            value: { replaceState },
        });
        setHash('#s3cr3t-token');
    });

    function setHash(hash) {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: {
                hash,
                pathname: '/password-reset/17',
                search: '',
                href: 'https://scoutmagic.be/password-reset/17' + hash,
            },
        });
    }

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/password-reset.js');
    }

    const shown = (id) => !document.getElementById(id).classList.contains('d-none');
    const lastBody = () => new URLSearchParams(fetch.mock.calls[0][1].body);

    describe('entry guard', () => {
        it('does nothing at all on a page that is not the reset page', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('the fragment', () => {
        it('strips the token from the address bar before doing anything else', async () => {
            await boot();

            expect(replaceState).toHaveBeenCalledWith(null, '', '/password-reset/17');
        });

        it('keeps the query string when stripping the fragment', async () => {
            setHash('#s3cr3t-token');
            window.location.search = '?from=mail';
            await boot();

            expect(replaceState).toHaveBeenCalledWith(null, '', '/password-reset/17?from=mail');
        });

        it('percent-decodes a token the mail client encoded', async () => {
            setHash('#a%2Bb%3Dc');
            await boot();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastBody().get('token')).toBe('a+b=c');
        });

        it('uses a malformed percent-escape as-is rather than throwing', async () => {
            setHash('#100%off');
            await boot();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(lastBody().get('token')).toBe('100%off');
        });

        it('goes straight to « no longer valid » with NO request when there is no fragment', async () => {
            setHash('');
            await boot();

            expect(fetch).not.toHaveBeenCalled();
            expect(shown('reset-invalid')).toBe(true);
            expect(shown('reset-checking')).toBe(false);
            expect(shown('reset-panel')).toBe(false);
        });
    });

    describe('the check', () => {
        it('posts form-encoded to the form\'s own action + /check', async () => {
            await boot();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const [url, opts] = fetch.mock.calls[0];
            expect(url).toBe('/password-reset/17/check');
            expect(opts.method).toBe('POST');
            // NOT JSON: the endpoint reads its token through $_POST.
            expect(opts.headers['Content-Type']).toBe('application/x-www-form-urlencoded');
            expect(lastBody().get('token')).toBe('s3cr3t-token');
            expect(lastBody().get('_csrf_token')).toBe('tok-123');
        });

        it('reveals the form and arms its hidden token when the link is good', async () => {
            await boot();

            await vi.waitFor(() => expect(shown('reset-panel')).toBe(true));
            expect(document.getElementById('reset-token').value).toBe('s3cr3t-token');
            expect(shown('reset-checking')).toBe(false);
            expect(shown('reset-invalid')).toBe(false);
        });

        it('shows « no longer valid » and NEVER arms the token when the link is dead', async () => {
            global.fetch = vi.fn(() => jsonResponse({ valid: false }));
            await boot();

            await vi.waitFor(() => expect(shown('reset-invalid')).toBe(true));
            expect(shown('reset-panel')).toBe(false);
            expect(document.getElementById('reset-token').value).toBe('');
        });

        it('shows « no longer valid » on the CSRF refusal (HTTP 403)', async () => {
            global.fetch = vi.fn(() => jsonResponse({ valid: false }, 403));
            await boot();

            await vi.waitFor(() => expect(shown('reset-invalid')).toBe(true));
            expect(shown('reset-panel')).toBe(false);
        });

        it('shows « no longer valid » on an answer with no `valid` at all', async () => {
            global.fetch = vi.fn(() => jsonResponse(null));
            await boot();

            await vi.waitFor(() => expect(shown('reset-invalid')).toBe(true));
        });

        it('shows « no longer valid » rather than hanging on « Vérification… » when the network dies', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();

            await vi.waitFor(() => expect(shown('reset-invalid')).toBe(true));
            expect(shown('reset-checking')).toBe(false);
        });

        it('shows « no longer valid » when the answer is not JSON at all', async () => {
            global.fetch = vi.fn(() => Promise.resolve({
                ok: true, status: 200, json: () => Promise.reject(new Error('not json')),
            }));
            await boot();

            await vi.waitFor(() => expect(shown('reset-invalid')).toBe(true));
        });
    });
});
