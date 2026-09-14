/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Isolated JavaScript unit test — no real service worker, PushManager or
// network: all mocked below. Exercises the REAL implementation in
// public/assets/js/push-subscribe.js (imported, never reimplemented).
//
// This file has no DOM at all, and that is the point: it is the one piece
// of the two push surfaces that is pure logic, shared by « Mon compte »
// and by the installed application's invitation (ARCHITECTURE.md §8.111).
// What it owes both callers is a STATUS STRING they can turn into the
// right sentence — 'denied' is the browser saying no and is not an error,
// everything that actually broke is 'error' — and telling those two apart
// is the whole reason the callers can stay thin.
import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('push-subscribe.js — the shared Web Push toolbox', () => {
    let getSubscription;
    let subscribe;
    let unsubscribe;
    let requestPermission;
    let push;

    beforeEach(async () => {
        vi.resetModules();
        document.body.innerHTML = '';

        unsubscribe = vi.fn(() => Promise.resolve(true));
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
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true }),
        }));

        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/push-subscribe.js');
        push = window.ScoutMagicPush;
    });

    // --- isSupported ---------------------------------------------------

    it('is supported when the browser has the three APIs and the server a key', () => {
        expect(push.isSupported('dGVzdA')).toBe(true);
    });

    /**
     * An installation with no VAPID pair renders an empty string, and
     * there is nothing to subscribe to — the browser's permission prompt
     * would be spent on nothing.
     */
    it('is not supported without a VAPID public key, however capable the browser', () => {
        expect(push.isSupported('')).toBe(false);
    });

    it('is not supported when the browser has no PushManager', () => {
        const saved = global.PushManager;
        delete global.PushManager;

        expect(push.isSupported('dGVzdA')).toBe(false);

        global.PushManager = saved;
    });

    // --- enable --------------------------------------------------------

    it('subscribes this device and registers the endpoint server-side', async () => {
        await expect(push.enable('dGVzdA')).resolves.toBe('enabled');

        expect(subscribe).toHaveBeenCalledWith(expect.objectContaining({ userVisibleOnly: true }));
        expect(global.fetch.mock.calls[0][0]).toBe('/api/push-subscription');

        const body = JSON.parse(global.fetch.mock.calls[0][1].body);
        expect(body.endpoint).toBe('https://push.example/abc');
        expect(body.auth_key).toBe('a');
        expect(body.p256dh_key).toBe('p');
    });

    /**
     * The base64url public key has to reach the browser as bytes. A
     * conversion that quietly produced the wrong ones would subscribe
     * successfully and never deliver a single notification, which is the
     * kind of failure nobody reports.
     */
    it('hands the key to the browser as bytes, not as the base64url string', async () => {
        // 'A_-A' is base64url for the three bytes 0x03 0xFF 0x80 — it
        // carries both substitutions ('-' and '_') and needs padding.
        await push.enable('A_-A');

        const key = subscribe.mock.calls[0][0].applicationServerKey;
        expect(Array.from(key)).toEqual([0x03, 0xff, 0x80]);
    });

    it('answers "denied" and subscribes nothing when the browser refuses', async () => {
        requestPermission.mockResolvedValue('denied');

        await expect(push.enable('dGVzdA')).resolves.toBe('denied');
        expect(subscribe).not.toHaveBeenCalled();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('answers "error" when the server refuses the subscription', async () => {
        global.fetch.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });

        await expect(push.enable('dGVzdA')).resolves.toBe('error');
    });

    it('answers "error" rather than throwing when the browser blows up', async () => {
        subscribe.mockRejectedValue(new Error('AbortError'));

        await expect(push.enable('dGVzdA')).resolves.toBe('error');
    });

    // --- disable -------------------------------------------------------

    it('unsubscribes this device and forgets the endpoint server-side', async () => {
        getSubscription.mockResolvedValue({ endpoint: 'https://push.example/abc', unsubscribe });

        await expect(push.disable()).resolves.toBe('disabled');

        expect(unsubscribe).toHaveBeenCalled();
        expect(global.fetch.mock.calls[0][1].method).toBe('DELETE');
        expect(JSON.parse(global.fetch.mock.calls[0][1].body).endpoint).toBe('https://push.example/abc');
    });

    /**
     * Nothing to unsubscribe is the state the caller was asking for, not
     * a failure to report.
     */
    it('answers "disabled" with nothing to unsubscribe, and calls nobody', async () => {
        await expect(push.disable()).resolves.toBe('disabled');

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('answers "error" when the endpoint could not be forgotten server-side', async () => {
        getSubscription.mockResolvedValue({ endpoint: 'https://push.example/abc', unsubscribe });
        global.fetch.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });

        await expect(push.disable()).resolves.toBe('error');
    });

    // --- currentSubscription -------------------------------------------

    it('reads this device\'s real subscription rather than a stored preference', async () => {
        const subscription = { endpoint: 'https://push.example/abc', unsubscribe };
        getSubscription.mockResolvedValue(subscription);

        await expect(push.currentSubscription()).resolves.toBe(subscription);
    });
});
