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
        expect(document.querySelector('.toast-body').textContent).toBe("Erreur lors de l'enregistrement.");
        expect(document.querySelector('.toast').className).toContain('text-bg-danger');
    });

    it('reverts .checked AND aria-checked together when the request itself fails', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await import('../../public/assets/js/notification-preferences.js');

        const toggle = document.querySelector('.notification-channel-toggle');
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(toggle.checked).toBe(false));
        expect(toggle.getAttribute('aria-checked')).toBe('false');
        expect(document.querySelector('.toast-body').textContent).toBe('Erreur réseau.');
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
        expect(document.querySelector('.toast-body').textContent).toBe('Enregistré.');
        expect(document.querySelector('.toast').className).toContain('text-bg-success');
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

    it('puts the discretion switch back, aria-checked included, when its save is refused', async () => {
        document.body.innerHTML +=
            '<input id="quiet-hours-start" value="21:00">' +
            '<input id="quiet-hours-end" value="07:00">' +
            '<input id="notification-discretion" type="checkbox" role="switch" aria-checked="false">';
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
        await import('../../public/assets/js/notification-preferences.js');

        const discretion = document.getElementById('notification-discretion');
        discretion.checked = true;
        discretion.setAttribute('aria-checked', 'true');
        discretion.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(discretion.checked).toBe(false));
        expect(discretion.getAttribute('aria-checked')).toBe('false');
    });

    it('puts a refused quiet hour back on the last recorded one', async () => {
        document.body.innerHTML +=
            '<input id="quiet-hours-start" value="21:00">' +
            '<input id="quiet-hours-end" value="07:00">' +
            '<input id="notification-discretion" type="checkbox">';
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
        await import('../../public/assets/js/notification-preferences.js');
        const start = document.getElementById('quiet-hours-start');

        start.value = '22:00';
        start.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        await new Promise((resolve) => setTimeout(resolve, 0));

        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
        start.value = '23:00';
        start.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(start.value).toBe('22:00'));
    });

    it('waits for the other half of the pair instead of saving a half-filled quiet range', async () => {
        document.body.innerHTML +=
            '<input id="quiet-hours-start" value="">' +
            '<input id="quiet-hours-end" value="">' +
            '<input id="notification-discretion" type="checkbox">';
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
        await import('../../public/assets/js/notification-preferences.js');
        const start = document.getElementById('quiet-hours-start');
        const end = document.getElementById('quiet-hours-end');

        start.value = '21:00';
        start.dispatchEvent(new Event('change'));
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(fetch).not.toHaveBeenCalled();
        expect(start.value).toBe('21:00');

        end.value = '07:00';
        end.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    });

    it('never lets an older refusal arriving late undo a newer accepted save', async () => {
        document.body.innerHTML +=
            '<input id="quiet-hours-start" value="21:00">' +
            '<input id="quiet-hours-end" value="07:00">' +
            '<input id="notification-discretion" type="checkbox">';
        const pending = [];
        global.fetch = vi.fn(() => new Promise((resolve) => pending.push(resolve)));
        await import('../../public/assets/js/notification-preferences.js');
        const start = document.getElementById('quiet-hours-start');
        const end = document.getElementById('quiet-hours-end');

        start.value = '22:00';
        start.dispatchEvent(new Event('change'));
        end.value = '06:00';
        end.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(pending).toHaveLength(2));

        pending[1]({ json: () => Promise.resolve({ success: true }) });
        await vi.waitFor(() => expect(document.querySelector('.toast-body')?.textContent).toBe('Enregistré.'));
        pending[0]({ json: () => Promise.resolve({ success: false }) });
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(start.value).toBe('22:00');
        expect(end.value).toBe('06:00');
    });

    it('still sends a discretion flip while the quiet range is half-filled, with the recorded pair', async () => {
        document.body.innerHTML +=
            '<input id="quiet-hours-start" value="21:00">' +
            '<input id="quiet-hours-end" value="07:00">' +
            '<input id="notification-discretion" type="checkbox">';
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
        await import('../../public/assets/js/notification-preferences.js');
        const start = document.getElementById('quiet-hours-start');
        const discretion = document.getElementById('notification-discretion');

        start.value = '';
        start.dispatchEvent(new Event('change'));
        discretion.checked = true;
        discretion.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        const body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.quiet_hours_start).toBe('21:00');
        expect(body.quiet_hours_end).toBe('07:00');
        expect(body.discretion).toBe(true);
    });

    it('sends a discretion flip back while the first flip is in flight, with quiet hours half-filled', async () => {
        document.body.innerHTML +=
            '<input id="quiet-hours-start" value="">' +
            '<input id="quiet-hours-end" value="">' +
            '<input id="notification-discretion" type="checkbox">';
        const pending = [];
        global.fetch = vi.fn(() => new Promise((resolve) => pending.push(resolve)));
        await import('../../public/assets/js/notification-preferences.js');
        const start = document.getElementById('quiet-hours-start');
        const discretion = document.getElementById('notification-discretion');
        start.value = '21:00';

        discretion.checked = true;
        discretion.dispatchEvent(new Event('change'));
        discretion.checked = false;
        discretion.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(pending).toHaveLength(2));
        expect(JSON.parse(fetch.mock.calls[1][1].body).discretion).toBe(false);
    });

    it('shows an older accepted save that answers after a newer refusal put the controls back', async () => {
        document.body.innerHTML +=
            '<input id="quiet-hours-start" value="21:00">' +
            '<input id="quiet-hours-end" value="07:00">' +
            '<input id="notification-discretion" type="checkbox">';
        const pending = [];
        global.fetch = vi.fn(() => new Promise((resolve) => pending.push(resolve)));
        await import('../../public/assets/js/notification-preferences.js');
        const start = document.getElementById('quiet-hours-start');
        const discretion = document.getElementById('notification-discretion');

        start.value = '22:00';
        start.dispatchEvent(new Event('change'));
        discretion.checked = true;
        discretion.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(pending).toHaveLength(2));

        pending[1]({ json: () => Promise.resolve({ success: false }) });
        await vi.waitFor(() => expect(start.value).toBe('21:00'));
        pending[0]({ json: () => Promise.resolve({ success: true }) });

        await vi.waitFor(() => expect(start.value).toBe('22:00'));
        expect(discretion.checked).toBe(false);
        const toasts = [...document.querySelectorAll('.toast-body')].map((el) => el.textContent);
        expect(toasts).toContain('Enregistré.');
    });

    it('says so with an error toast when the quiet-hours save fails', async () => {
        document.body.innerHTML +=
            '<input id="quiet-hours-start" value="21:00">' +
            '<input id="quiet-hours-end" value="07:00">' +
            '<input id="notification-discretion" type="checkbox">';
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
        await import('../../public/assets/js/notification-preferences.js');

        document.getElementById('quiet-hours-start').dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(document.querySelector('.toast-body')).not.toBeNull());
        expect(document.querySelector('.toast-body').textContent).toBe("Erreur lors de l'enregistrement.");
        expect(document.querySelector('.toast').className).toContain('text-bg-danger');
    });
});
