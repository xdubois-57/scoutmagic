/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The PDF thumbnail fallback, in one place.
//
// A receipt stored as a PDF is shown through /files/{id}/thumbnail, an
// image the server renders with Ghostscript. It can be missing: the file
// predates thumbnailing, Ghostscript is not installed on that host, or
// the PDF defeated it. Without a fallback the visitor gets the browser's
// broken-image glyph — the one piece of UI on the page that is not the
// site's, and which says nothing about what the file is.
//
// This exact handler existed three times over (finance-receipts.js,
// finance-movements.js, and inline in the finance dashboard's template),
// each with its own dimensions, and each easy to forget on the next
// screen that shows a receipt. `error` does not bubble, which is why
// this is a per-image listener rather than one delegated handler, and
// why every code path that INSERTS such an image has to call bind()
// again for the images it just added.
//
// Usage: window.ScoutMagicPdfThumbnail.bind(root, { height: '160px' })
(function () {
    /**
     * Wires the fallback on every `img.pdf-thumbnail` inside `root` that
     * does not already have it.
     *
     * @param {ParentNode|null|undefined} root
     * @param {{height?: string, width?: string, iconClass?: string}} [options]
     * @returns {void}
     */
    function bind(root, options) {
        if (!root) {
            return;
        }
        var opts = options || {};
        var height = opts.height || '160px';
        var width = opts.width || '';
        var iconClass = opts.iconClass || 'fs-1';

        /** @type {NodeListOf<HTMLImageElement>} */ (
            root.querySelectorAll('img.pdf-thumbnail')
        ).forEach(function (img) {
            // bind() is called again on every re-render, and a list can
            // be re-rendered around images that survived it.
            if (img.dataset.pdfFallbackBound === '1') {
                return;
            }
            img.dataset.pdfFallbackBound = '1';

            img.addEventListener('error', function () {
                var fallback = document.createElement('div');
                fallback.className =
                    'd-flex align-items-center justify-content-center bg-body-secondary rounded';
                fallback.style.height = height;
                if (width) {
                    fallback.style.width = width;
                }
                // The alternative text of the image being replaced is
                // the filename; the icon itself carries no information a
                // screen reader needs twice.
                fallback.innerHTML = '<i class="bi bi-file-earmark-pdf ' + iconClass
                    + '" aria-hidden="true"></i>';
                img.replaceWith(fallback);
            }, { once: true });
        });
    }

    window.ScoutMagicPdfThumbnail = { bind: bind };
})();
