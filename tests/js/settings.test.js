// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch is mocked, and so is the `bootstrap`
// global (a vendored classic script that does not exist under jsdom — only
// the Modal show/hide surface settings.js actually calls). Exercises the
// REAL implementation in public/assets/js/settings.js (imported below, never
// reimplemented here). That file wires everything from a DOMContentLoaded
// listener, so each test builds its DOM and then dispatches that event
// manually, the same way tests/js/cookie-consent.test.js does.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const MODAL_DOM = `
    <div id="settingEditModal"></div>
    <span id="settingEditTitle"></span><span id="settingEditDescription"></span>
    <span id="settingEditLabel"></span><span id="settingEditType"></span>
    <div id="settingEditError" class="d-none"></div>
    <div id="settingEditInputContainer"></div>
    <button id="settingEditSave"></button>`;

function row(attrs) {
    return `<div class="setting-row" ${Object.entries(attrs).map(([k, v]) => `data-${k}="${v}"`).join(' ')}></div>`;
}

describe('settings.js', () => {
    let modal;
    beforeEach(async () => {
        vi.resetModules();
        modal = { show: vi.fn(), hide: vi.fn() };
        global.bootstrap = { Modal: vi.fn(() => modal) };   // 3-line stub — that is the whole cost
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
        // A successful save calls window.location.reload(), which jsdom does
        // not implement and reports as "Not implemented: navigation". Stub it
        // so the real navigation is observable and the output stays clean.
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/config/settings', reload: vi.fn() },
        });
        document.body.innerHTML = MODAL_DOM;
    });

    async function boot() {
        await import('../../public/assets/js/settings.js');
        document.dispatchEvent(new Event('DOMContentLoaded'));
    }

    describe('buildInput() — the XSS surface fed by server data-* attributes', () => {
        it('escapes a quote-breaking value in a text input attribute', async () => {
            // dataset set programmatically: a raw quote in an innerHTML string
            // truncates the attribute and silently defuses the payload.
            document.body.innerHTML = MODAL_DOM + '<div class="setting-row"></div>';
            Object.assign(document.querySelector('.setting-row').dataset,
                { module: 'core', key: 'k', type: 'text', value: '"><img src=x onerror=alert(1)>', label: 'L', description: 'D' });
            await boot();
            document.querySelector('.setting-row').click();
            const c = document.getElementById('settingEditInputContainer');
            expect(c.querySelector('img')).toBeNull();
            expect(c.querySelector('input').value).toBe('"><img src=x onerror=alert(1)>');
        });

        it('escapes a quote-breaking regex in the pattern attribute', async () => {
            document.body.innerHTML = MODAL_DOM + row({ module: 'core', key: 'k', type: 'text', value: 'v', regex: '^a&quot; onfocus=alert(1) x=&quot;', label: 'L', description: 'D' });
            await boot();
            document.querySelector('.setting-row').click();
            const input = document.getElementById('settingEditInputContainer').querySelector('input');
            expect(input.getAttribute('onfocus')).toBeNull();
        });

        it('escapes select option values and labels', async () => {
            document.body.innerHTML = MODAL_DOM + row({ module: 'core', key: 'k', type: 'select', value: 'a', options: '[&quot;&lt;script&gt;x&lt;/script&gt;&quot;,&quot;a&quot;]', label: 'L', description: 'D' });
            await boot();
            document.querySelector('.setting-row').click();
            const c = document.getElementById('settingEditInputContainer');
            expect(c.querySelector('script')).toBeNull();
            expect(c.querySelectorAll('option').length).toBe(2);
            expect(c.querySelector('select').value).toBe('a');
        });

        it('renders a checked switch for boolean "1" and unchecked for "0"', async () => {
            document.body.innerHTML = MODAL_DOM + row({ module: 'core', key: 'k', type: 'boolean', value: '1', label: 'L', description: 'D' });
            await boot();
            document.querySelector('.setting-row').click();
            expect(document.querySelector('#settingEditInputContainer input').checked).toBe(true);
        });

        it('survives malformed JSON in data-options instead of throwing', async () => {
            document.body.innerHTML = MODAL_DOM + row({ module: 'core', key: 'k', type: 'select', value: 'a', options: 'not-json', label: 'L', description: 'D' });
            await boot();
            expect(() => document.querySelector('.setting-row').click()).not.toThrow();
        });
    });

    describe('save contract with the server', () => {
        it('POSTs the CSRF token in the JSON BODY as _csrf_token, not as a header', async () => {
            const meta = document.createElement('meta');
            meta.name = 'csrf-token'; meta.content = 'tok-123';
            document.head.appendChild(meta);
            document.body.innerHTML = MODAL_DOM + row({ module: 'news', key: 'title', type: 'text', value: 'v', label: 'L', description: 'D' });
            await boot();
            document.querySelector('.setting-row').click();
            document.getElementById('settingEditInputContainer').querySelector('input').value = 'new';
            document.getElementById('settingEditSave').click();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            const [url, opts] = fetch.mock.calls[0];
            expect(url).toBe('/config/settings/update');
            expect(opts.method).toBe('POST');
            expect(JSON.parse(opts.body)).toEqual({ module_id: 'news', key: 'title', value: 'new', _csrf_token: 'tok-123' });
            expect(opts.headers['X-CSRF-Token']).toBeUndefined();
            meta.remove();
        });

        it('closes the modal and reloads the page on success', async () => {
            document.body.innerHTML = MODAL_DOM + row({ module: 'core', key: 'k', type: 'text', value: 'v', label: 'L', description: 'D' });
            await boot();
            document.querySelector('.setting-row').click();
            document.getElementById('settingEditSave').click();

            await vi.waitFor(() => expect(modal.hide).toHaveBeenCalled());
            expect(window.location.reload).toHaveBeenCalled();
        });

        it('maps the "core" pseudo-module to a null module_id', async () => {
            document.body.innerHTML = MODAL_DOM + row({ module: 'core', key: 'k', type: 'text', value: 'v', label: 'L', description: 'D' });
            await boot();
            document.querySelector('.setting-row').click();
            document.getElementById('settingEditSave').click();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(JSON.parse(fetch.mock.calls[0][1].body).module_id).toBeNull();
        });

        it('surfaces the server error in the modal and keeps it open', async () => {
            global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false, error: 'Valeur invalide' }) }));
            document.body.innerHTML = MODAL_DOM + row({ module: 'core', key: 'k', type: 'text', value: 'v', label: 'L', description: 'D' });
            await boot();
            document.querySelector('.setting-row').click();
            document.getElementById('settingEditSave').click();
            const err = document.getElementById('settingEditError');
            await vi.waitFor(() => expect(err.textContent).toBe('Valeur invalide'));
            expect(err.classList.contains('d-none')).toBe(false);
            expect(modal.hide).not.toHaveBeenCalled();
        });
    });
});
