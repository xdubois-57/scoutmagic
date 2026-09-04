/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The camera half of the door screen, and the wake lock that keeps the
// phone awake while it is open.
//
// **The scan is a comfort; the validation is the feature.** Everything
// here sits on top of news-scan.js, which already owns the round trip and
// the verdict: a decoded payload is handed to
// window.ScoutMagicNewsScan.setQuery() rather than resolved a second time.
// The manual field stays visible at all times and is never folded behind
// an « autres options » — a refused camera, a dark hall, a cracked screen,
// and the staff types the reference.
//
// **html5-qrcode, vendored, and only on this page.** The native
// BarcodeDetector would have been free, but it exists on Chrome and
// Android and is absent or partial on iPhone depending on the version —
// and a good half of the animateurs hold the door with an iPhone, where
// the scan would simply not work.
//
// **The scanner accepts two forms**, and that is what makes the two codes
// in one e-mail harmless: a bare reference, and the transfer payload of
// the SEPA QR. Neither is told apart here — the raw text goes to the
// server, which recognises both (Modules\Finance\Api\EpcPayloadReaderInterface).
// Under the pressure of a queue somebody holds out the wrong code, and
// the answer is not to remove one but to make the confusion harmless.
(function () {
    const config = window.ScoutMagicApi && window.ScoutMagicApi.pageData('news-scan-data');
    const openButton = /** @type {HTMLButtonElement|null} */ (document.getElementById('news-scan-open-camera'));
    const frame = document.getElementById('news-scan-camera');
    const readerEl = document.getElementById('news-scan-reader');
    const closeButton = /** @type {HTMLButtonElement|null} */ (document.getElementById('news-scan-close-camera'));
    const errorEl = document.getElementById('news-scan-camera-error');
    if (!config || !openButton || !frame || !readerEl || !closeButton) return;

    /** @type {any} */
    let scanner = null;
    /** @type {any} */
    let wakeLock = null;
    let lastPayload = '';
    let lastPayloadAt = 0;

    // A camera reads the same code thirty times a second while it is
    // held in frame. Without this the screen would validate, redraw, and
    // re-resolve continuously — so the same payload is ignored for two
    // seconds, which is longer than it takes to move a phone away and
    // shorter than the gap between two visitors.
    const REPEAT_GUARD_MS = 2000;

    /**
     * Asks for the screen wake lock, if this browser has the API.
     *
     * Without it the animateur's phone dims and then locks between two
     * visitors, and has to be woken — sometimes with a code — in front of
     * the queue. Where the API is missing (it exists on Chrome, Android,
     * and Safari since 16.4) NOTHING happens: there is no fallback to
     * write, and pretending otherwise would mean keeping a video element
     * alive to fool a screensaver.
     */
    async function requestWakeLock() {
        if (!('wakeLock' in navigator) || wakeLock) return;

        try {
            wakeLock = await navigator.wakeLock.request('screen');
            // The browser drops it by itself in some conditions; forget
            // our handle so the visibility listener below can ask again.
            wakeLock.addEventListener('release', function () { wakeLock = null; });
        } catch {
            // Refused (a battery-saver mode, a policy): the screen still
            // works, it just dims. Never a message — this is a comfort.
            wakeLock = null;
        }
    }

    async function releaseWakeLock() {
        if (!wakeLock) return;
        try {
            await wakeLock.release();
        } catch (e) {
            // Already gone; nothing to do.
        }
        wakeLock = null;
    }

    /**
     * What was decoded, handed to the one round trip that already exists.
     *
     * @param {string} decoded
     */
    function handleDecoded(decoded) {
        const now = Date.now();
        if (decoded === lastPayload && now - lastPayloadAt < REPEAT_GUARD_MS) return;
        lastPayload = decoded;
        lastPayloadAt = now;

        const scan = window.ScoutMagicNewsScan;
        if (!scan) return;
        // Raw, not parsed: a bare reference and a transfer payload are
        // told apart on the server, in the one place that knows the
        // format.
        scan.setQuery(decoded);
    }

    /** @param {string} message */
    function showError(message) {
        if (!errorEl) return;
        errorEl.textContent = message;
        errorEl.hidden = false;
    }

    function hideError() {
        if (!errorEl) return;
        errorEl.hidden = true;
    }

    async function openCamera() {
        hideError();

        // @ts-ignore — the vendored library's global (public/assets/vendor/html5-qrcode).
        const Html5Qrcode = window.Html5Qrcode;
        if (typeof Html5Qrcode !== 'function') {
            showError("Le lecteur de code n'a pas pu se charger. Saisissez la référence ci-dessous.");
            return;
        }

        frame.hidden = false;
        openButton.hidden = true;

        scanner = new Html5Qrcode(readerEl.id, { verbose: false });
        try {
            await scanner.start(
                // The back camera on a phone, the only one pointed at a
                // visitor's screen.
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 240, height: 240 } },
                handleDecoded,
                // Per-frame decode failures are the normal state of a
                // camera looking at a table; they are not errors.
                function () {}
            );
        } catch (e) {
            await closeCamera();
            showError("La caméra n'est pas disponible : autorisez-la, ou saisissez la référence ci-dessous.");
        }
    }

    async function closeCamera() {
        frame.hidden = true;
        openButton.hidden = false;

        if (!scanner) return;
        try {
            await scanner.stop();
            scanner.clear();
        } catch (e) {
            // Already stopped.
        }
        scanner = null;
    }

    openButton.addEventListener('click', openCamera);
    closeButton.addEventListener('click', closeCamera);

    // The classic trap of this API: the browser RELEASES the lock when the
    // page goes to the background and never restores it on its own. It
    // works in a test, and then stops working after the first time
    // somebody answers a phone call.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            requestWakeLock();
        }
    });

    // Released on leaving, or the battery of somebody who forgot the tab
    // is what pays for it.
    window.addEventListener('pagehide', function () {
        releaseWakeLock();
        closeCamera();
    });

    requestWakeLock();

    window.ScoutMagicNewsScanReader = {
        openCamera: openCamera,
        closeCamera: closeCamera,
        handleDecoded: handleDecoded,
        requestWakeLock: requestWakeLock,
        releaseWakeLock: releaseWakeLock,
    };
})();
