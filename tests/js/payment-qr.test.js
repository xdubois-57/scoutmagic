// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises the
// REAL implementation in public/assets/js/payment-qr.js (imported below,
// never reimplemented here).
//
// Bootstrap is not loaded: the module listens to `shown.bs.modal` and
// `hidden.bs.modal`, which Bootstrap dispatches (bubbling) on the modal
// element, so the tests dispatch those directly. jsdom has no Wake Lock
// API; each test installs a recording stand-in on `navigator.wakeLock`.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const MARKUP = `
    <button type="button" data-bs-toggle="modal" data-bs-target="#qr-paiement" data-qr-thumbnail>
        <img src="data:image/png;base64,AAAA" alt="QR code de paiement SEPA">
    </button>
    <div class="modal" id="qr-paiement"><img src="data:image/png;base64,AAAA" data-qr-enlarged></div>
    <div class="modal" id="autre"><p>Un autre dialogue</p></div>`;

/** A Wake Lock stand-in recording what it was asked. */
function fakeWakeLock() {
    const calls = { requests: 0, releases: 0 };
    const api = {
        request: vi.fn(() => {
            calls.requests++;
            return Promise.resolve({
                release: () => {
                    calls.releases++;
                    return Promise.resolve();
                },
            });
        }),
    };
    Object.defineProperty(navigator, 'wakeLock', { value: api, configurable: true });

    return calls;
}

/** @param {string} id @param {string} type */
function fire(id, type) {
    /** @type {HTMLElement} */ (document.getElementById(id)).dispatchEvent(new Event(type, { bubbles: true }));
}

/** Lets the promise chain of a request settle. */
async function settle() {
    await Promise.resolve();
    await Promise.resolve();
}

// Imported once: the module's listeners live on the document, so a second
// import would only stack a second set of them. Each test starts from a
// closed dialog instead (afterEach).
let imported = false;

async function load() {
    document.body.innerHTML = MARKUP;
    if (!imported) {
        await import('../../public/assets/js/payment-qr.js');
        imported = true;
    }
}

describe('payment-qr.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    afterEach(async () => {
        // Back to « closed, nothing held » for the next test.
        if (document.getElementById('qr-paiement')) {
            fire('qr-paiement', 'hidden.bs.modal');
        }
        await settle();
        delete (/** @type {any} */ (navigator)).wakeLock;
    });

    it('keeps the screen awake while the enlarged QR is shown', async () => {
        const calls = fakeWakeLock();
        await load();

        fire('qr-paiement', 'shown.bs.modal');
        await settle();

        expect(navigator.wakeLock.request).toHaveBeenCalledWith('screen');
        expect(calls.requests).toBe(1);
        expect(calls.releases).toBe(0);
    });

    it('gives the screen back when the enlarged QR is closed', async () => {
        const calls = fakeWakeLock();
        await load();

        fire('qr-paiement', 'shown.bs.modal');
        await settle();
        fire('qr-paiement', 'hidden.bs.modal');

        expect(calls.releases).toBe(1);
    });

    it('releases a lock that arrives after the dialog was already closed', async () => {
        const calls = fakeWakeLock();
        await load();

        fire('qr-paiement', 'shown.bs.modal');
        fire('qr-paiement', 'hidden.bs.modal');
        await settle();

        expect(calls.requests).toBe(1);
        expect(calls.releases).toBe(1);
    });

    it('leaves every other dialog alone', async () => {
        const calls = fakeWakeLock();
        await load();

        fire('autre', 'shown.bs.modal');
        await settle();

        expect(calls.requests).toBe(0);
    });

    it('asks again when the page comes back while the QR is still open', async () => {
        // The browser drops a wake lock whenever the page is hidden.
        const calls = fakeWakeLock();
        await load();

        fire('qr-paiement', 'shown.bs.modal');
        await settle();
        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
        await settle();
        delete (/** @type {any} */ (document)).visibilityState;

        expect(calls.requests).toBe(2);
    });

    it('does nothing at all in a browser without the Wake Lock API', async () => {
        await load();

        expect(() => {
            fire('qr-paiement', 'shown.bs.modal');
            fire('qr-paiement', 'hidden.bs.modal');
        }).not.toThrow();
    });

    it('swallows a refusal: the code stays on screen and still scans', async () => {
        Object.defineProperty(navigator, 'wakeLock', {
            value: { request: vi.fn(() => Promise.reject(new Error('NotAllowedError'))) },
            configurable: true,
        });
        await load();

        fire('qr-paiement', 'shown.bs.modal');
        await settle();

        expect(navigator.wakeLock.request).toHaveBeenCalledTimes(1);
    });
});
