// Isolated JavaScript unit test — jsdom-simulated DOM only. No real
// network: fetch mocked below. Exercises the REAL implementation in
// public/assets/js/notification-preferences.js (imported below, never
// reimplemented here).
//
// Focus: aria-checked stays in sync with .checked when a failed save
// reverts a toggle programmatically — that revert fires no native 'change'
// event, so public/assets/js/nav.js's delegated listener can't see it
// (SonarCloud Web:S6807). The user-driven case (a real click firing
// 'change') is covered by tests/js/nav.test.js instead.
import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('notification-preferences.js — aria-checked stays in sync on a reverted toggle', () => {
    beforeEach(async () => {
        vi.resetModules();
        document.body.innerHTML =
            '<div id="notification-preferences">' +
            '  <input class="notification-channel-toggle" type="checkbox" role="switch" aria-checked="false"' +
            '         data-type-id="1" data-channel="in_app">' +
            '</div>';

        window.ScoutMagicNav = {
            syncSwitchAriaChecked(input) {
                input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
            },
        };

        global.Notification = { permission: 'granted' };

        // The real toolboxes — notification-preferences.js posts through
        // window.ScoutMagicApi and confirms the quiet-hours save with a
        // window.ScoutMagicToast toast (base.html.twig guarantees this
        // load order in production).
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/toast.js');
    });

    it('reverts .checked AND aria-checked together when the server reports failure', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
        await import('../../public/assets/js/notification-preferences.js');

        const toggle = document.querySelector('.notification-channel-toggle');
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(toggle.checked).toBe(false));
        expect(toggle.getAttribute('aria-checked')).toBe('false');
    });

    it('reverts .checked AND aria-checked together when the request itself fails', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await import('../../public/assets/js/notification-preferences.js');

        const toggle = document.querySelector('.notification-channel-toggle');
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(toggle.checked).toBe(false));
        expect(toggle.getAttribute('aria-checked')).toBe('false');
    });

    it('leaves .checked and aria-checked untouched when the save succeeds', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
        await import('../../public/assets/js/notification-preferences.js');

        const toggle = document.querySelector('.notification-channel-toggle');
        toggle.checked = true;
        toggle.setAttribute('aria-checked', 'true');
        toggle.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        await new Promise((resolve) => setTimeout(resolve, 0)); // let the response chain settle
        expect(toggle.checked).toBe(true);
        expect(toggle.getAttribute('aria-checked')).toBe('true');
    });

    it('confirms a successful quiet-hours save with an "Enregistré." toast (replacing the inline notice)', async () => {
        document.body.innerHTML +=
            '<input id="quiet-hours-start" value="21:00">' +
            '<input id="quiet-hours-end" value="07:00">' +
            '<input id="notification-discretion" type="checkbox">';
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
        await import('../../public/assets/js/notification-preferences.js');

        document.getElementById('quiet-hours-start').dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe('Enregistré.');
        expect(document.querySelector('.toast').className).toContain('text-bg-success');
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/notifications/quiet-hours');
        expect(JSON.parse(init.body)).toEqual({
            quiet_hours_start: '21:00',
            quiet_hours_end: '07:00',
            discretion: false,
            _csrf_token: '',
        });
    });

    it('shows no toast when the quiet-hours save fails', async () => {
        document.body.innerHTML +=
            '<input id="quiet-hours-start" value="21:00">' +
            '<input id="quiet-hours-end" value="07:00">' +
            '<input id="notification-discretion" type="checkbox">';
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
        await import('../../public/assets/js/notification-preferences.js');

        document.getElementById('quiet-hours-start').dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        await new Promise((resolve) => setTimeout(resolve, 0)); // let the response chain settle
        expect(document.querySelector('.toast-body')).toBeNull();
    });
});
