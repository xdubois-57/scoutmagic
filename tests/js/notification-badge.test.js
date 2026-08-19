// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network: fetch, navigator.setAppBadge/clearAppBadge and
// navigator.serviceWorker are mocked below. Exercises the REAL
// implementation in public/assets/js/notification-badge.js (imported below,
// never reimplemented here). That file is an IIFE that reads the DOM and
// starts polling at import time, so each test builds its DOM first and then
// imports the module via vi.resetModules() + await import().
//
// vi.clearAllTimers() in beforeEach is load-bearing: the IIFE registers a
// 60s setInterval with no teardown hook, so without it the intervals from
// earlier tests in this file keep firing and the poll-count assertions read
// high.
import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('notification-badge.js wiring', () => {
    let swListener;
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllTimers();   // IIFE setInterval leaks across imports — see note
        vi.useFakeTimers();
        document.body.innerHTML = '<span class="notification-badge d-none"></span>';
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ count: 3 }) }));
        navigator.setAppBadge = vi.fn(() => Promise.resolve());
        navigator.clearAppBadge = vi.fn(() => Promise.resolve());
        swListener = null;
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { addEventListener: (t, cb) => { if (t === 'message') swListener = cb; } },
        });
    });

    it('hits the unread-count endpoint on load and renders the count', async () => {
        await import('../../public/assets/js/notification-badge.js');
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(fetch).toHaveBeenCalledWith('/api/notifications/unread-count', { headers: { Accept: 'application/json' } });
        const badge = document.querySelector('.notification-badge');
        await vi.waitFor(() => expect(badge.textContent).toBe('3'));
        expect(badge.classList.contains('d-none')).toBe(false);
        expect(navigator.setAppBadge).toHaveBeenCalledWith(3);
    });

    it('clamps a count above 99 to "99+" but badges the OS with the true count', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ count: 250 }) }));
        await import('../../public/assets/js/notification-badge.js');
        await vi.waitFor(() => expect(document.querySelector('.notification-badge').textContent).toBe('99+'));
        expect(navigator.setAppBadge).toHaveBeenCalledWith(250);
    });

    it('clears the OS app badge when the count reaches zero (the stale-badge bug)', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ count: 0 }) }));
        await import('../../public/assets/js/notification-badge.js');
        await vi.waitFor(() => expect(navigator.clearAppBadge).toHaveBeenCalled());
        expect(document.querySelector('.notification-badge').classList.contains('d-none')).toBe(true);
        expect(navigator.setAppBadge).not.toHaveBeenCalled();
    });

    it('re-polls every 60s', async () => {
        await import('../../public/assets/js/notification-badge.js');
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('renders immediately from a push message without re-fetching', async () => {
        await import('../../public/assets/js/notification-badge.js');
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        swListener({ data: { type: 'push-received', unreadCount: 7 } });
        expect(document.querySelector('.notification-badge').textContent).toBe('7');
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('falls back to a re-fetch when the push carries no count', async () => {
        await import('../../public/assets/js/notification-badge.js');
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        swListener({ data: { type: 'push-received' } });
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('keeps the server-rendered count when the endpoint fails', async () => {
        document.body.innerHTML = '<span class="notification-badge">4</span>';
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await import('../../public/assets/js/notification-badge.js');
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        expect(document.querySelector('.notification-badge').textContent).toBe('4');
    });

    it('does nothing at all when no badge is present', async () => {
        document.body.innerHTML = '';
        await import('../../public/assets/js/notification-badge.js');
        expect(fetch).not.toHaveBeenCalled();
    });
});
