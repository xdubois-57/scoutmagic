/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The social composer's live card (issue #706, IT-02): wires the page's
// <canvas> to public/assets/js/social-card.js, which does the drawing.
//
// **Progressive enhancement, deliberately.** The page still renders the
// server's composed card as an <img> — `preview_path`, the route that has
// always served it. This file draws the same card into a <canvas> beside
// it and swaps the two only once the first draw has SUCCEEDED. A browser
// with no 2D context, a page whose JavaScript did not load, a tainted
// canvas: in each the <img> is still there and still correct. That matters
// more here than on most pages, because « Publier » is the only button on
// a source-backed composer, so a page that showed no image at all would
// make the first POST an irreversible public post of something nobody saw
// (SECURITY.md says so, and IT-01's own review turned on it).
//
// **Why a canvas at all, then.** The <img> costs a round trip per change,
// and from IT-02 the title follows the typing and the blur follows a
// slider. The chantier rules out a waiting delay before the preview
// refreshes, so the drawing has to be local.
//
// The redraw is throttled to the screen's refresh rate with
// requestAnimationFrame — not a timer: two keystrokes inside one frame
// should cost one draw, and a debounce would show the card lagging behind
// the typing, which is the thing being judged.
(function () {
    const root = document.querySelector('[data-card-composer]');
    // A no-op on any page that loads this file without the composer.
    if (!root) {
        return;
    }

    const canvas = /** @type {HTMLCanvasElement|null} */ (root.querySelector('[data-card-canvas]'));
    const fallback = /** @type {HTMLImageElement|null} */ (root.querySelector('[data-card-preview]'));
    const engine = window.ScoutMagicCard;
    if (!canvas || !engine) {
        // Nothing to draw with: the <img> stays exactly as the server
        // rendered it, and the canvas (which is hidden until a draw
        // succeeds) stays hidden.
        return;
    }

    const titleField = /** @type {HTMLInputElement|null} */ (document.getElementById('communication-title'));
    const address = canvas.getAttribute('data-card-address') || '';
    const blurRatio = parseFloat(canvas.getAttribute('data-card-blur') || '0') || 0;
    const backgroundUrl = canvas.getAttribute('data-card-background') || '';

    /** @type {HTMLImageElement|null} */
    let background = null;
    let frame = 0;
    let shown = false;

    function title() {
        return titleField ? titleField.value : (canvas.getAttribute('data-card-title') || '');
    }

    /**
     * Draws, and on the first success hands the canvas the <img>'s place.
     *
     * The swap happens here rather than on load so that a context that
     * exists but cannot draw — a tainted canvas throws on export, some
     * privacy modes return a context that paints nothing — never leaves
     * the page with a blank square where the card was.
     */
    function draw() {
        frame = 0;
        const drawn = engine.drawCard(canvas, {
            image: background,
            title: title(),
            address: address,
            blurRatio: blurRatio,
        });
        if (!drawn || shown) {
            return;
        }

        shown = true;
        canvas.hidden = false;
        if (fallback) {
            // Removed rather than hidden: two images of the same card, one
            // of them stale, is what a screen reader would announce twice.
            fallback.remove();
        }
    }

    function schedule() {
        if (frame !== 0) {
            return;
        }
        frame = window.requestAnimationFrame(draw);
    }

    if (titleField) {
        titleField.addEventListener('input', schedule);
    }

    if (backgroundUrl !== '') {
        const image = new Image();
        // The background comes from this site, so the canvas is never
        // tainted and `toBlob()` keeps working — which is what « Publier »
        // will need. Set before `src`, as the attribute only counts then.
        image.crossOrigin = 'anonymous';
        image.addEventListener('load', function () {
            background = image;
            schedule();
        });
        // A background that will not load leaves the server's <img> in
        // place: a card with a grey square where the photo was is worse
        // than the card the server already drew.
        image.addEventListener('error', function () {
            background = null;
        });
        image.src = backgroundUrl;
    }
}());
