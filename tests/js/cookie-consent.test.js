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

    // The banner disappearing is what tells a visitor their choice was
    // recorded, so it must never outrun the server actually recording it.
    // Both handlers used to remove it from inside `fetch().then()`, which
    // runs for a 403 as readily as a 200 — a refused decision looked
    // exactly like an accepted one. A dynamic-security run caught
    // POST /cookies/reject-all answering 403 with the banner gone anyway.
    // Every case below was red against that implementation.
    describe('when the server refuses the choice', () => {
        beforeEach(() => {
            global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 403 }));
            window.ScoutMagicToast = { show: vi.fn() };
        });

        it('keeps the banner when the POST is refused, so the choice can still be made', async () => {
            renderBanner();
            document.dispatchEvent(new Event('DOMContentLoaded'));

            document.getElementById('cookie-reject-all').click();
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            expect(document.getElementById('cookie-banner')).not.toBeNull();
        });

        it('says so rather than failing silently', async () => {
            renderBanner();
            document.dispatchEvent(new Event('DOMContentLoaded'));

            document.getElementById('cookie-accept-all').click();
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                expect.stringContaining("n'a pas pu être enregistré"),
                expect.objectContaining({ variant: 'error' })
            );
        });

        // Withdrawing consent purges the theme preference and tells the
        // service worker to stop caching. Doing that on a refused refusal
        // would act on a decision the server never stored.
        it('does not withdraw the stored theme preference when the refusal was not recorded', async () => {
            renderBanner();
            localStorage.setItem('theme_preference', 'sombre');
            document.dispatchEvent(new Event('DOMContentLoaded'));

            document.getElementById('cookie-reject-all').click();
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            expect(localStorage.getItem('theme_preference')).toBe('sombre');
        });

        it('keeps the banner when the network fails outright', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('network down')));
            renderBanner();
            document.dispatchEvent(new Event('DOMContentLoaded'));

            document.getElementById('cookie-accept-all').click();
            await vi.waitFor(() => expect(window.ScoutMagicToast.show).toHaveBeenCalled());

            expect(document.getElementById('cookie-banner')).not.toBeNull();
        });
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
