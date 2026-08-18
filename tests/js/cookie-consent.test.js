// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch() is mocked below. Exercises the REAL
// implementation in public/assets/js/cookie-consent.js (imported below,
// never reimplemented here). That file wires everything from a
// `DOMContentLoaded` listener, so each test dispatches that event manually
// after building the DOM it expects (jsdom's document has already reached
// 'complete' readyState by the time this module import runs, so the real
// event would never fire again on its own).
import { beforeEach, describe, expect, it, vi } from 'vitest';
import '../../public/assets/js/cookie-consent.js';

function renderBanner() {
    document.body.innerHTML = `
        <div id="cookie-banner">
            <button id="cookie-accept-all">Accepter tout</button>
            <button id="cookie-reject-all">Refuser tout</button>
            <button id="cookie-customize">Personnaliser</button>
        </div>
    `;
}

describe('cookie-consent.js', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        global.fetch = vi.fn(() => Promise.resolve({ ok: true }));
    });

    it('does nothing (no fetch, no throw) when there is no cookie banner in the DOM', () => {
        document.body.innerHTML = '';

        expect(() => document.dispatchEvent(new Event('DOMContentLoaded'))).not.toThrow();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('calls POST /cookies/accept-all and removes the banner when "Accepter tout" is clicked', async () => {
        renderBanner();
        document.dispatchEvent(new Event('DOMContentLoaded'));

        document.getElementById('cookie-accept-all').click();
        await vi.waitFor(() => expect(document.getElementById('cookie-banner')).toBeNull());

        expect(fetch).toHaveBeenCalledWith('/cookies/accept-all', expect.objectContaining({ method: 'POST' }));
    });

    it('calls POST /cookies/reject-all and removes the banner when "Refuser tout" is clicked', async () => {
        renderBanner();
        document.dispatchEvent(new Event('DOMContentLoaded'));

        document.getElementById('cookie-reject-all').click();
        await vi.waitFor(() => expect(document.getElementById('cookie-banner')).toBeNull());

        expect(fetch).toHaveBeenCalledWith('/cookies/reject-all', expect.objectContaining({ method: 'POST' }));
    });

    it('navigates to /cookies when "Personnaliser" is clicked, without calling fetch', () => {
        renderBanner();
        document.dispatchEvent(new Event('DOMContentLoaded'));
        Object.defineProperty(window, 'location', { configurable: true, value: { href: '' } });

        document.getElementById('cookie-customize').click();

        expect(window.location.href).toBe('/cookies');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('sends the CSRF token header from the page meta tag when accepting', async () => {
        renderBanner();
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'test-csrf-token';
        document.head.appendChild(meta);
        document.dispatchEvent(new Event('DOMContentLoaded'));

        document.getElementById('cookie-accept-all').click();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

        expect(fetch).toHaveBeenCalledWith(
            '/cookies/accept-all',
            expect.objectContaining({ headers: { 'X-CSRF-Token': 'test-csrf-token' } })
        );
    });
});
