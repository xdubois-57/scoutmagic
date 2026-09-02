// Isolated JavaScript unit test — jsdom-simulated DOM only, no camera, no
// network. Exercises the REAL implementation in
// public/assets/js/news-scan-reader.js (imported below, never
// reimplemented here) — the camera half of the door screen and its wake
// lock.
//
// jsdom has neither a camera nor the Screen Wake Lock API, so both seams
// are stubbed: window.Html5Qrcode (the vendored library's own global) and
// navigator.wakeLock. What is under test is what this file does around
// them — never the library itself.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** @type {{start: any, stop: any, clear: any}} */
let scannerInstance;
/** @type {{request: any}} */
let wakeLock;
/** @type {{release: any, addEventListener: any}} */
let sentinel;
/** @type {{setQuery: any}} */
let scan;

function buildScreen() {
    document.body.innerHTML = `
        <button id="news-scan-open-camera">Scanner un billet</button>
        <div id="news-scan-camera" hidden>
            <div id="news-scan-reader"></div>
            <button id="news-scan-close-camera">Fermer</button>
        </div>
        <p id="news-scan-camera-error" hidden></p>
        <script type="application/json" id="news-scan-data">
        {"formId":7,"lookupUrl":"/news/scan/7/lookup","validateUrl":"/news/scan/7/validate","csrfToken":"tok","expectsPayment":true}
        </script>
    `;
}

function stubEnvironment({ startRejects = false, libraryMissing = false } = {}) {
    // @ts-ignore — only pageData is reached from this file.
    window.ScoutMagicApi = { pageData: (id) => JSON.parse(document.getElementById(id).textContent) };

    scan = { setQuery: vi.fn().mockResolvedValue(undefined), lookup: vi.fn() };
    // @ts-ignore — the round trip news-scan.js already owns.
    window.ScoutMagicNewsScan = scan;

    scannerInstance = {
        start: startRejects ? vi.fn().mockRejectedValue(new Error('NotAllowedError')) : vi.fn().mockResolvedValue(undefined),
        stop: vi.fn().mockResolvedValue(undefined),
        clear: vi.fn(),
    };
    // @ts-ignore — the vendored library's global.
    window.Html5Qrcode = libraryMissing ? undefined : vi.fn().mockImplementation(() => scannerInstance);

    sentinel = { release: vi.fn().mockResolvedValue(undefined), addEventListener: vi.fn() };
    wakeLock = { request: vi.fn().mockResolvedValue(sentinel) };
    Object.defineProperty(navigator, 'wakeLock', { value: wakeLock, configurable: true, writable: true });
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/news-scan-reader.js');
}

/** @returns {any} */
function reader() {
    // @ts-ignore — the global the file exposes for exactly this.
    return window.ScoutMagicNewsScanReader;
}

beforeEach(() => {
    buildScreen();
    stubEnvironment();
});

describe('the wake lock', () => {
    it('is asked for as soon as the screen is open', async () => {
        // Without it the animateur's phone dims and locks between two
        // visitors, and has to be woken — sometimes with a code — in
        // front of the queue.
        await load();

        await vi.waitFor(() => expect(wakeLock.request).toHaveBeenCalledWith('screen'));
    });

    it('is asked for again when the page comes back from the background', async () => {
        // The classic trap of this API: the browser releases the lock on
        // its own and never restores it. It works in a test, then stops
        // working after the first phone call.
        await load();
        await vi.waitFor(() => expect(wakeLock.request).toHaveBeenCalledTimes(1));

        await reader().releaseWakeLock();
        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.waitFor(() => expect(wakeLock.request).toHaveBeenCalledTimes(2));
    });

    it('is released when the page goes away', async () => {
        // Otherwise the battery of somebody who forgot the tab pays for
        // it.
        await load();
        await vi.waitFor(() => expect(wakeLock.request).toHaveBeenCalled());

        window.dispatchEvent(new Event('pagehide'));

        await vi.waitFor(() => expect(sentinel.release).toHaveBeenCalled());
    });

    it('does nothing at all where the API is missing', async () => {
        // Chrome, Android and Safari 16.4 have it; elsewhere there is no
        // fallback to write, and pretending otherwise would mean keeping
        // a video element alive to fool a screensaver.
        // @ts-ignore
        delete navigator.wakeLock;
        await load();

        await expect(reader().requestWakeLock()).resolves.toBeUndefined();
    });

    it('carries on when the lock is refused', async () => {
        // A battery-saver mode, a policy: the screen still works, it just
        // dims. Never a message — this is a comfort.
        wakeLock.request.mockRejectedValue(new Error('NotAllowedError'));
        await load();

        await expect(reader().requestWakeLock()).resolves.toBeUndefined();
        expect(document.getElementById('news-scan-camera-error').hidden).toBe(true);
    });
});

describe('the camera', () => {
    it('is not started until somebody asks for it', async () => {
        // Nobody wants a camera turning itself on because they opened a
        // tab.
        await load();

        expect(window.Html5Qrcode).not.toHaveBeenCalled();
        expect(document.getElementById('news-scan-camera').hidden).toBe(true);
    });

    it('starts on the back camera when the button is pressed', async () => {
        await load();
        document.getElementById('news-scan-open-camera').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(scannerInstance.start).toHaveBeenCalled());
        // The only camera pointed at a visitor's screen.
        expect(scannerInstance.start.mock.calls[0][0]).toEqual({ facingMode: 'environment' });
        expect(document.getElementById('news-scan-camera').hidden).toBe(false);
    });

    it('says so and keeps the manual field usable when the camera is refused', async () => {
        stubEnvironment({ startRejects: true });
        await load();
        document.getElementById('news-scan-open-camera').dispatchEvent(new Event('click'));

        await vi.waitFor(() => {
            expect(document.getElementById('news-scan-camera-error').hidden).toBe(false);
        });
        expect(document.getElementById('news-scan-camera-error').textContent).toContain('saisissez la référence');
        // Back to the button, not stuck on a black frame.
        expect(document.getElementById('news-scan-camera').hidden).toBe(true);
    });

    it('says so when the vendored library did not load', async () => {
        stubEnvironment({ libraryMissing: true });
        await load();
        document.getElementById('news-scan-open-camera').dispatchEvent(new Event('click'));

        await vi.waitFor(() => {
            expect(document.getElementById('news-scan-camera-error').hidden).toBe(false);
        });
        expect(document.getElementById('news-scan-camera').hidden).toBe(true);
    });

    it('stops the camera when it is closed', async () => {
        await load();
        document.getElementById('news-scan-open-camera').dispatchEvent(new Event('click'));
        await vi.waitFor(() => expect(scannerInstance.start).toHaveBeenCalled());

        document.getElementById('news-scan-close-camera').dispatchEvent(new Event('click'));

        await vi.waitFor(() => expect(scannerInstance.stop).toHaveBeenCalled());
        expect(document.getElementById('news-scan-camera').hidden).toBe(true);
    });
});

describe('what was decoded', () => {
    it('goes to the one round trip that already exists, raw', async () => {
        // A bare reference and a transfer payload are told apart on the
        // server, in the one place that knows the format.
        await load();

        reader().handleDecoded('X7K2-9QMF-A3');

        expect(scan.setQuery).toHaveBeenCalledWith('X7K2-9QMF-A3');
    });

    it('passes an EPC transfer payload through untouched', async () => {
        // The confirmation of a paid event carries two codes, and under
        // the pressure of a queue somebody holds out the wrong one. The
        // answer is not to remove a code but to make the confusion
        // harmless.
        const payload = 'BCD\n002\n1\nSCT\n\nUnité SV025\nBE71096123456769\nEUR46.00\n\n\n+++123/4567/89412+++';
        await load();

        reader().handleDecoded(payload);

        expect(scan.setQuery).toHaveBeenCalledWith(payload);
    });

    it('ignores the same code held in frame', async () => {
        // A camera reads the same code thirty times a second while it is
        // held up; without this the screen would re-resolve continuously.
        await load();

        reader().handleDecoded('X7K2-9QMF-A3');
        reader().handleDecoded('X7K2-9QMF-A3');
        reader().handleDecoded('X7K2-9QMF-A3');

        expect(scan.setQuery).toHaveBeenCalledTimes(1);
    });

    it('resolves the next visitor\'s code at once', async () => {
        await load();

        reader().handleDecoded('X7K2-9QMF-A3');
        reader().handleDecoded('M4T8-2WPC-K9');

        expect(scan.setQuery).toHaveBeenCalledTimes(2);
    });
});
