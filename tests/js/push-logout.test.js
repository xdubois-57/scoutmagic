// Isolated JavaScript unit test — jsdom only. No PHP server, no network:
// navigator.serviceWorker and the push subscription are mocked. Exercises
// the REAL implementation in public/assets/js/push-logout.js (imported
// below, never reimplemented here): logging out unsubscribes this device
// and hands its endpoint to the server in the logout POST (issue #1123).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const ENDPOINT = 'https://push.example/device-1';

function logoutForm(id = 'logout') {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = '/logout';
    form.setAttribute('action', '/logout');
    form.id = id;
    form.innerHTML = '<input type="hidden" name="_csrf_token" value="t"><button type="submit">Se déconnecter</button>';
    form.submit = vi.fn();
    document.body.appendChild(form);
    return form;
}

function stubServiceWorker(getRegistration) {
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: { getRegistration },
    });
}

function withSubscription(subscription) {
    stubServiceWorker(vi.fn(() => Promise.resolve({
        pushManager: { getSubscription: vi.fn(() => Promise.resolve(subscription)) },
    })));
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/push-logout.js');
}

function submit(form) {
    const event = new Event('submit', { cancelable: true });
    form.dispatchEvent(event);
    return event;
}

function endpointField(form) {
    return form.querySelector('input[name="push_endpoint"]');
}

beforeEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

afterEach(() => {
    vi.useRealTimers();
    delete navigator.serviceWorker;
});

describe('push-logout.js: releasing the device at logout', () => {
    it('unsubscribes the device, puts its endpoint in the form, then sends the form', async () => {
        const subscription = { endpoint: ENDPOINT, unsubscribe: vi.fn(() => Promise.resolve(true)) };
        withSubscription(subscription);
        const form = logoutForm();
        await boot();

        const event = submit(form);

        expect(event.defaultPrevented).toBe(true);
        await vi.waitFor(() => expect(form.submit).toHaveBeenCalledTimes(1));
        expect(subscription.unsubscribe).toHaveBeenCalledTimes(1);
        expect(endpointField(form).value).toBe(ENDPOINT);
        expect(endpointField(form).type).toBe('hidden');
        // The endpoint was in the form before the form left.
        expect(subscription.unsubscribe.mock.invocationCallOrder[0])
            .toBeLessThan(form.submit.mock.invocationCallOrder[0]);
    });

    it('wires every logout form on the page', async () => {
        withSubscription({ endpoint: ENDPOINT, unsubscribe: vi.fn(() => Promise.resolve(true)) });
        const desktop = logoutForm('desktop');
        const mobile = logoutForm('mobile');
        await boot();

        submit(mobile);

        await vi.waitFor(() => expect(mobile.submit).toHaveBeenCalledTimes(1));
        expect(endpointField(mobile).value).toBe(ENDPOINT);
        expect(desktop.submit).not.toHaveBeenCalled();
    });

    it('logs out with no endpoint when the device has no subscription', async () => {
        withSubscription(null);
        const form = logoutForm();
        await boot();

        submit(form);

        await vi.waitFor(() => expect(form.submit).toHaveBeenCalledTimes(1));
        expect(endpointField(form)).toBeNull();
    });

    it('logs out when no service worker is registered', async () => {
        stubServiceWorker(vi.fn(() => Promise.resolve(undefined)));
        const form = logoutForm();
        await boot();

        submit(form);

        await vi.waitFor(() => expect(form.submit).toHaveBeenCalledTimes(1));
    });

    it('logs out when the browser has no service worker support at all', async () => {
        const form = logoutForm();
        await boot();

        submit(form);

        await vi.waitFor(() => expect(form.submit).toHaveBeenCalledTimes(1));
    });

    it('still sends the endpoint when unsubscribing fails in the browser', async () => {
        withSubscription({ endpoint: ENDPOINT, unsubscribe: vi.fn(() => Promise.reject(new Error('nope'))) });
        const form = logoutForm();
        await boot();

        submit(form);

        await vi.waitFor(() => expect(form.submit).toHaveBeenCalledTimes(1));
        expect(endpointField(form).value).toBe(ENDPOINT);
    });

    it('never holds the logout longer than its budget when the browser never answers', async () => {
        vi.useFakeTimers();
        stubServiceWorker(vi.fn(() => new Promise(() => {})));
        const form = logoutForm();
        await boot();

        submit(form);
        await vi.advanceTimersByTimeAsync(1400);
        expect(form.submit).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(200);

        expect(form.submit).toHaveBeenCalledTimes(1);
    });

    it('a second tap while the first is being released sends the form once', async () => {
        let resolve;
        stubServiceWorker(vi.fn(() => new Promise((r) => { resolve = r; })));
        const form = logoutForm();
        await boot();

        submit(form);
        const second = submit(form);
        expect(second.defaultPrevented).toBe(true);
        resolve(undefined);

        await vi.waitFor(() => expect(form.submit).toHaveBeenCalledTimes(1));
        await new Promise((r) => setTimeout(r, 0));
        expect(form.submit).toHaveBeenCalledTimes(1);
    });

    // offline-nav.js refuses every form while offline, in the capture
    // phase: nothing is sent, so nothing may be released or re-sent.
    it('leaves alone a submit something earlier already refused', async () => {
        const subscription = { endpoint: ENDPOINT, unsubscribe: vi.fn(() => Promise.resolve(true)) };
        withSubscription(subscription);
        const form = logoutForm();
        await boot();
        const refuse = (event) => event.preventDefault();
        document.addEventListener('submit', refuse, true);

        submit(form);
        await new Promise((r) => setTimeout(r, 0));

        document.removeEventListener('submit', refuse, true);
        expect(form.submit).not.toHaveBeenCalled();
        expect(subscription.unsubscribe).not.toHaveBeenCalled();
    });

    it('never touches a form with another action', async () => {
        withSubscription({ endpoint: ENDPOINT, unsubscribe: vi.fn(() => Promise.resolve(true)) });
        const form = document.createElement('form');
        form.setAttribute('action', '/login');
        document.body.appendChild(form);
        await boot();

        const event = submit(form);

        expect(event.defaultPrevented).toBe(false);
    });
});
