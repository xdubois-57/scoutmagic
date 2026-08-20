// Isolated JavaScript unit test — jsdom-simulated DOM only. No real
// service worker, PushManager or network: all mocked below. Exercises the
// REAL implementation in public/assets/js/push-notifications.js (imported
// below, never reimplemented here).
//
// Focus: aria-checked stays in sync with .checked through every path that
// sets .checked WITHOUT the browser firing a native 'change' event —
// public/assets/js/nav.js's delegated listener can't see those (SonarCloud
// Web:S6807). The user-driven case (a real click firing 'change') is
// covered by tests/js/nav.test.js instead.
import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('push-notifications.js — aria-checked stays in sync on programmatic .checked changes', () => {
    let getSubscription;
    let subscribe;
    let requestPermission;

    beforeEach(async () => {
        vi.resetModules();
        document.body.innerHTML =
            '<input id="push-toggle" type="checkbox" role="switch" aria-checked="false" data-vapid-public-key="dGVzdA">' +
            '<p id="push-denied-notice" class="d-none"></p>' +
            '<p id="push-error-notice" class="d-none"></p>';

        window.ScoutMagicNav = {
            syncSwitchAriaChecked(input) {
                input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
            },
        };

        getSubscription = vi.fn(() => Promise.resolve(null));
        subscribe = vi.fn(() => Promise.resolve({ toJSON: () => ({ endpoint: 'e', keys: { auth: 'a', p256dh: 'p' } }) }));
        requestPermission = vi.fn(() => Promise.resolve('granted'));

        global.Notification = { permission: 'default', requestPermission };
        global.PushManager = function () {};
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { ready: Promise.resolve({ pushManager: { getSubscription, subscribe } }) },
        });
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
    });

    it('reflects "no subscription" on load with aria-checked kept in sync (no native change event fires here)', async () => {
        await import('../../public/assets/js/push-notifications.js');
        const toggle = document.getElementById('push-toggle');
        await vi.waitFor(() => expect(getSubscription).toHaveBeenCalled());
        await vi.waitFor(() => expect(toggle.getAttribute('aria-checked')).toBe('false'));
        expect(toggle.checked).toBe(false);
    });

    it('reverts to unchecked with aria-checked in sync when the browser denies permission', async () => {
        requestPermission.mockResolvedValue('denied');
        await import('../../public/assets/js/push-notifications.js');
        const toggle = document.getElementById('push-toggle');
        await vi.waitFor(() => expect(getSubscription).toHaveBeenCalled());

        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(document.getElementById('push-denied-notice').classList.contains('d-none')).toBe(false));
        expect(toggle.checked).toBe(false);
        expect(toggle.getAttribute('aria-checked')).toBe('false');
    });

    it('reverts to unchecked with aria-checked in sync when the server rejects the subscription', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
        await import('../../public/assets/js/push-notifications.js');
        const toggle = document.getElementById('push-toggle');
        await vi.waitFor(() => expect(getSubscription).toHaveBeenCalled());

        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(document.getElementById('push-error-notice').classList.contains('d-none')).toBe(false));
        expect(toggle.checked).toBe(false);
        expect(toggle.getAttribute('aria-checked')).toBe('false');
    });
});
