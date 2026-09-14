/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Isolated JavaScript unit test — jsdom-simulated DOM only. No real
// service worker, PushManager or network: all mocked below. Exercises the
// REAL implementations in public/assets/js/push-invitation.js and
// public/assets/js/push-subscribe.js (imported, never reimplemented).
//
// Focus: the decision. Whether the dialog opens at all is the half of the
// feature the server cannot make (ARCHITECTURE.md §8.111), it is made
// synchronously so that help-discovery.js can read it, and each of its
// four "no" answers looks like a perfectly working page — which is why
// every one of them is pinned here.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** The markup partials/push_invitation_dialog.html.twig renders. */
function renderDialog() {
    document.body.innerHTML =
        '<div class="modal" id="push-invitation-modal">' +
        '  <div id="push-invitation-body" data-vapid-public-key="dGVzdA">' +
        '    <output class="d-none" data-push-invitation-denied></output>' +
        '    <p class="d-none" data-push-invitation-error></p>' +
        '    <output class="d-none" data-push-invitation-success></output>' +
        '  </div>' +
        '  <button type="button" data-push-invitation-enable>Activer les notifications</button>' +
        '  <button type="button" class="d-none" data-push-invitation-done>Terminé</button>' +
        '  <button type="button" data-push-invitation-later>Plus tard</button>' +
        '</div>';
}

describe('push-invitation.js — « Activer les notifications ? »', () => {
    let getSubscription;
    let subscribe;
    let requestPermission;
    let modalShow;

    beforeEach(async () => {
        vi.resetModules();
        renderDialog();

        getSubscription = vi.fn(() => Promise.resolve(null));
        subscribe = vi.fn(() => Promise.resolve({
            toJSON: () => ({ endpoint: 'https://push.example/abc', keys: { auth: 'a', p256dh: 'p' } }),
        }));
        requestPermission = vi.fn(() => Promise.resolve('granted'));

        global.Notification = { permission: 'default', requestPermission };
        global.PushManager = function () {};
        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { ready: Promise.resolve({ pushManager: { getSubscription, subscribe } }) },
        });
        // Installed application, everywhere but iOS Safari.
        window.matchMedia = vi.fn(() => ({ matches: true }));

        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true }),
        }));

        modalShow = vi.fn();
        window.bootstrap = {
            Modal: {
                getOrCreateInstance: () => ({ show: modalShow, hide: () => {} }),
                getInstance: () => ({ show: modalShow, hide: () => {} }),
            },
        };

        // The real fetch toolbox and the real subscribe toolbox —
        // base.html.twig guarantees this load order in production.
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/push-subscribe.js');
    });

    /** @returns {Promise<void>} */
    async function load() {
        await import('../../public/assets/js/push-invitation.js');
    }

    /** @returns {object} the body of the nth fetch call, parsed */
    function sentBody(nth = 0) {
        return JSON.parse(global.fetch.mock.calls[nth][1].body);
    }

    // --- The decision --------------------------------------------------

    it('opens in the installed application when the device has never been asked', async () => {
        await load();

        expect(window.ScoutMagicPushInvitation.showing).toBe(true);
        expect(modalShow).toHaveBeenCalled();
    });

    it('stays shut in an ordinary browser tab', async () => {
        window.matchMedia = vi.fn(() => ({ matches: false }));
        await load();

        expect(window.ScoutMagicPushInvitation.showing).toBe(false);
        expect(modalShow).not.toHaveBeenCalled();
    });

    // iOS Safari has no display-mode media query, and navigator.standalone
    // is the only signal there — the case this dialog exists for most.
    it('opens on an iPhone, where only navigator.standalone says so', async () => {
        window.matchMedia = vi.fn(() => ({ matches: false }));
        Object.defineProperty(window.navigator, 'standalone', { configurable: true, value: true });
        await load();

        expect(window.ScoutMagicPushInvitation.showing).toBe(true);

        Object.defineProperty(window.navigator, 'standalone', { configurable: true, value: undefined });
    });

    it('stays shut once permission was granted — that device already answered', async () => {
        global.Notification.permission = 'granted';
        await load();

        expect(window.ScoutMagicPushInvitation.showing).toBe(false);
    });

    it('stays shut once permission was denied — the browser will not ask again', async () => {
        global.Notification.permission = 'denied';
        await load();

        expect(window.ScoutMagicPushInvitation.showing).toBe(false);
    });

    it('stays shut when the installation has no VAPID key pair', async () => {
        document.getElementById('push-invitation-body').dataset.vapidPublicKey = '';
        await load();

        expect(window.ScoutMagicPushInvitation.showing).toBe(false);
    });

    it('says "not showing" on a page carrying no dialog at all', async () => {
        document.body.innerHTML = '';
        await load();

        expect(window.ScoutMagicPushInvitation.showing).toBe(false);
    });

    // --- Accepting -----------------------------------------------------

    it('subscribes this device and records the answer as accepted', async () => {
        await load();

        document.querySelector('[data-push-invitation-enable]').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(subscribe).toHaveBeenCalled());
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));

        expect(global.fetch.mock.calls[0][0]).toBe('/api/push-subscription');
        expect(sentBody(0).endpoint).toBe('https://push.example/abc');
        expect(global.fetch.mock.calls[1][0]).toBe('/api/notifications/invitation');
        expect(sentBody(1).action).toBe('enabled');
    });

    it('shows the confirmation and swaps the two answers for « Terminé »', async () => {
        await load();

        document.querySelector('[data-push-invitation-enable]').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(
            document.querySelector('[data-push-invitation-success]').classList.contains('d-none')
        ).toBe(false));

        expect(document.querySelector('[data-push-invitation-enable]').classList.contains('d-none')).toBe(true);
        expect(document.querySelector('[data-push-invitation-later]').classList.contains('d-none')).toBe(true);
        expect(document.querySelector('[data-push-invitation-done]').classList.contains('d-none')).toBe(false);
    });

    /**
     * The dismissal that follows an accepted invitation must not send a
     * second, contradicting answer — « Terminé » would otherwise record
     * « Plus tard » on somebody who just said yes.
     */
    it('records nothing more when the accepted dialog is then closed', async () => {
        await load();

        document.querySelector('[data-push-invitation-enable]').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));

        document.getElementById('push-invitation-modal').dispatchEvent(new Event('hidden.bs.modal'));

        expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    // --- Refusing ------------------------------------------------------

    it('records « Plus tard » when the dialog is dismissed', async () => {
        await load();

        document.getElementById('push-invitation-modal').dispatchEvent(new Event('hidden.bs.modal'));

        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
        expect(global.fetch.mock.calls[0][0]).toBe('/api/notifications/invitation');
        expect(sentBody(0).action).toBe('later');
        expect(subscribe).not.toHaveBeenCalled();
    });

    it('records one answer and one only, whatever closes the dialog twice', async () => {
        await load();

        const modal = document.getElementById('push-invitation-modal');
        modal.dispatchEvent(new Event('hidden.bs.modal'));
        modal.dispatchEvent(new Event('hidden.bs.modal'));

        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
    });

    /**
     * A browser refusal is not an error: nothing broke and there is
     * nothing to retry, so the dialog stays open on its explanation and
     * the dismissal that follows means « Plus tard ».
     */
    it('explains a refused permission without calling it an error', async () => {
        requestPermission.mockResolvedValue('denied');
        await load();

        document.querySelector('[data-push-invitation-enable]').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(
            document.querySelector('[data-push-invitation-denied]').classList.contains('d-none')
        ).toBe(false));
        expect(document.querySelector('[data-push-invitation-error]').classList.contains('d-none')).toBe(true);
        expect(subscribe).not.toHaveBeenCalled();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('shows the error notice when the subscription could not be registered', async () => {
        global.fetch.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
        await load();

        document.querySelector('[data-push-invitation-enable]').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(
            document.querySelector('[data-push-invitation-error]').classList.contains('d-none')
        ).toBe(false));
        expect(document.querySelector('[data-push-invitation-success]').classList.contains('d-none')).toBe(true);
    });
});

describe('help-discovery.js stands down for the invitation', () => {
    beforeEach(() => {
        vi.resetModules();
        document.body.innerHTML =
            '<div class="modal" id="help-discovery-modal">' +
            '  <p data-discovery-position></p>' +
            '  <div data-discovery-card data-discovery-id="installer-application"></div>' +
            '  <button type="button" data-discovery-next></button>' +
            '</div>';
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) }));
        window.bootstrap = {
            Modal: {
                getOrCreateInstance: () => ({ show: vi.fn(), hide: vi.fn() }),
                getInstance: () => ({ show: vi.fn(), hide: vi.fn() }),
            },
        };
    });

    /**
     * Two modals in a row is what makes people close both without reading
     * either — and the tips one consumes its batch on the way out, so the
     * cost of getting this wrong is a tip nobody ever read being marked
     * as read.
     */
    it('shows no tip and consumes nothing while the invitation is opening', async () => {
        window.ScoutMagicPushInvitation = { showing: true };

        await import('../../public/assets/js/help-discovery.js');
        document.getElementById('help-discovery-modal').dispatchEvent(new Event('hidden.bs.modal'));

        expect(document.querySelector('[data-discovery-position]').textContent).toBe('');
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('shows its tips as usual when the invitation is not opening', async () => {
        window.ScoutMagicPushInvitation = { showing: false };

        await import('../../public/assets/js/help-discovery.js');

        expect(document.querySelector('[data-discovery-position]').textContent).toBe('1 / 1');
    });
});
