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
    const blurField = /** @type {HTMLInputElement|null} */ (document.querySelector('[data-card-blur-input]'));
    const sharpNote = /** @type {HTMLElement|null} */ (document.querySelector('[data-card-blur-sharp]'));
    const blurredNote = /** @type {HTMLElement|null} */ (document.querySelector('[data-card-blur-blurred]'));
    const address = canvas.getAttribute('data-card-address') || '';
    const backgroundUrl = canvas.getAttribute('data-card-background') || '';
    /** The canvas carries the strength the server computed; the slider then owns it. */
    const startingBlur = parseFloat(canvas.getAttribute('data-card-blur') || '0') || 0;

    /** @type {HTMLImageElement|null} */
    let background = null;
    let frame = 0;
    let shown = false;

    function title() {
        return titleField ? titleField.value : (canvas.getAttribute('data-card-title') || '');
    }

    /**
     * The blur the card is drawn with: the slider when the page offers
     * one, otherwise what the server computed.
     *
     * The slider is absent for an uploaded image — nothing to blur — and
     * once something has left, where the card is frozen.
     */
    function blur() {
        if (!blurField) {
            return startingBlur;
        }

        return parseFloat(blurField.value) || 0;
    }

    /**
     * Says, in words and only when it is true, that a gallery photo is
     * about to leave recognisable.
     *
     * Two sentences rather than one that changes: a screen reader
     * announces a replaced sentence as new text either way, and two
     * elements let the page say the reassuring half too.
     */
    function sayWhatTheBlurMeans() {
        if (!blurField) {
            return;
        }
        const sharp = blur() <= 0;
        if (sharpNote) {
            sharpNote.hidden = !sharp;
        }
        if (blurredNote) {
            blurredNote.hidden = sharp;
        }
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
            blurRatio: blur(),
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

    if (blurField) {
        blurField.addEventListener('input', function () {
            // The sentence first, so it is right even on a frame the
            // browser decides to skip.
            sayWhatTheBlurMeans();
            schedule();
        });
        sayWhatTheBlurMeans();
    }

    // ————— « Publier » sends the card the page drew —————

    const form = /** @type {HTMLFormElement|null} */ (root.closest('[data-card-form]'));
    const cardField = /** @type {HTMLInputElement|null} */ (document.querySelector('[data-card-file]'));
    let sending = false;

    /**
     * Holds the publish button for the life of the request, label and all.
     *
     * Done here rather than by the shared `form-submit-lock.js`: that
     * file's deferred unlock reads `defaultPrevented` and gives the
     * button back, which is right for a form whose submission was refused
     * and wrong for one whose default was prevented ON PURPOSE, to attach
     * an image before posting it. Opting in would re-open the double
     * submit on the one page where a double submit is a second public
     * post.
     */
    function hold(button) {
        sending = true;
        if (!button) {
            return;
        }
        button.disabled = true;
        if (button.dataset.publishLabel === undefined) {
            button.dataset.publishLabel = button.textContent || '';
        }
        button.textContent = 'Publication en cours…';
    }

    /**
     * Posts the form with the card attached.
     *
     * **`form.submit()` does not include the button that submitted**, so
     * `action=publish` would simply be absent and the controller would
     * fall through to « no action », saving nothing and publishing
     * nothing. The value is carried in a hidden field instead — the kind
     * of thing that looks like it works until the one POST that matters.
     */
    function postWith(blob) {
        if (blob && cardField && typeof DataTransfer === 'function') {
            try {
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], 'carte.jpg', { type: 'image/jpeg' }));
                cardField.files = transfer.files;
            } catch (error) {
                // Safari had no DataTransfer constructor until 14.1. The
                // card simply does not travel, and the server composes
                // one — which is the same path a share made before IT-02
                // takes, so nothing is lost but the exactness.
            }
        }

        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'publish';
        form.appendChild(action);
        form.submit();
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            const submitter = /** @type {HTMLButtonElement|null} */ (event.submitter);
            // The gallery and upload buttons are round trips that keep the
            // draft; only « Publier » sends an image.
            if (!submitter || submitter.value !== 'publish') {
                return;
            }
            if (sending) {
                event.preventDefault();

                return;
            }
            // Nothing drawn: let the form post as it is, and the server
            // composes the card as it always did.
            if (!shown) {
                hold(submitter);

                return;
            }

            event.preventDefault();
            // Taken before the first await, so the click that starts the
            // export is also the click that closes the button.
            hold(submitter);
            engine.toJpeg(canvas).then(postWith, function () {
                postWith(null);
            });
        });
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
