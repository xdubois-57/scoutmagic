/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * Two things, and the second is the one that matters.
 *
 * 1. The in-app viewer (views/partials/file_viewer.html.twig): a click on
 *    a `[data-file-viewer]` trigger shows the file HERE, with a Fermer
 *    button, instead of navigating to it.
 *
 * 2. **The safety net.** Any link to `/files/…` that nobody decorated is
 *    given `download` and `target="_blank"` before it can be followed.
 *
 * The second exists because of how this failure is shaped. In the
 * installed application the manifest declares `display: standalone` with
 * `scope: /`: the window has no address bar and no back button, and every
 * URL of this site stays inside it. A link to a file is a NAVIGATION — the
 * window leaves the application and lands on the file, and on iOS nothing
 * is left to press. The user force-quits the app. A single forgotten
 * attribute, in any template, does that; so rather than trusting ten
 * templates to remember, the net catches them all, including the links
 * other scripts insert after this one runs.
 *
 * It is a net, not the mechanism. `Http\Controller\FileController` answers
 * `attachment` to a navigation whatever the markup says, and
 * `partials/file_link.html.twig` writes the attributes where a template
 * knows to use it. Three independent guards, because the failure ends
 * with somebody killing the application.
 */
(function () {
    'use strict';

    // The document this script was loaded into, captured rather than read
    // from the global on every call. The MutationObserver below outlives
    // any single call, and reading a global from inside a callback is how
    // a callback ends up asking a document that is no longer the one it
    // was watching.
    var doc = document;
    // Captured for the same reason as the document above: the
    // MutationObserver's callback outlives any single call, and reading
    // globals from inside it is how a callback ends up asking a window
    // that is no longer the one it was watching.
    var win = window;

    /**
     * Whether this is the INSTALLED application rather than a browser tab.
     *
     * The same idiom as assets/js/offline-cache.js, and the distinction
     * this whole file turns on. A browser tab has a back button: a
     * navigation to a file there is an inconvenience. The installed app
     * has none, and iOS makes it worse than elsewhere — `download` and
     * `target="_blank"`, which are enough on Android and on the desktop,
     * are both handled IN the standalone window there. What comes up is
     * Safari's own download screen — « image006.jpg, Image JPEG - 2 ko,
     * Ouvrir dans Aperçu » — and it has no back button either. Reported
     * exactly like that, after the first fix.
     *
     * So on iOS the attributes are not the answer: the click must not
     * navigate at all.
     */
    function isStandalone() {
        return (win.matchMedia && win.matchMedia('(display-mode: standalone)').matches)
            || win.navigator.standalone === true;
    }

    /**
     * The overlay, built on first use rather than rendered on every page.
     *
     * It used to be a partial included by base.html.twig, and that was
     * wrong for a reason a test caught: the public rental tracking page
     * asserts that it offers NO download at all — « an external renter
     * downloads nothing from this site » (§6.24/§6.26) — and an overlay
     * carrying a « download » attribute made that page fail its own rule
     * while offering nothing. Dead markup is never only dead: it is
     * markup somebody else's assertion can see.
     *
     * @type {HTMLElement|null}
     */
    var viewer = null;
    var image;
    var fallback;
    var fallbackText;
    var name;
    var download;
    var closeButton;

    /**
     * The net, for a browser TAB. Same-origin file links only: an external
     * link is somebody else's page and `download` on it is ignored anyway.
     *
     * Not applied in the installed app, and that is the correction this
     * file went through. `download` is exactly what puts iOS on its own
     * download screen inside the standalone window — the attribute meant
     * to keep the page still is what takes it away. There, the click is
     * intercepted instead (see the handler at the bottom) and nothing
     * navigates at all.
     */
    function protect(link) {
        if (link.hasAttribute('data-file-viewer') || link.hasAttribute('data-file-link-raw')) {
            return;
        }
        if (!link.hasAttribute('download')) {
            link.setAttribute('download', '');
        }
        if (!link.hasAttribute('target')) {
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener');
        }
    }

    function protectAll() {
        if (isStandalone()) {
            return;
        }
        doc.querySelectorAll('a[href^="/files/"]').forEach(function (link) {
            protect(/** @type {HTMLAnchorElement} */ (link));
        });
    }

    protectAll();

    // Links another script inserted, and links inside a modal opened
    // later: the net has to cover what was not in the document when it
    // ran, or it only covers the easy half.
    if (typeof MutationObserver === 'function') {
        new MutationObserver(protectAll).observe(doc.body, { childList: true, subtree: true });
    }

    /**
     * Built once, on the first click that needs it.
     *
     * Plain DOM calls rather than one innerHTML string: nothing here is
     * user text, but a file name IS, and it reaches `textContent` below.
     * Building the frame the same way keeps the whole overlay free of any
     * place a name could be read as markup.
     */
    function build() {
        viewer = doc.createElement('div');
        viewer.id = 'file-viewer';
        viewer.className = 'file-viewer d-none';

        closeButton = doc.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'btn btn-link text-white position-absolute top-0 end-0 m-2 fs-3';
        closeButton.setAttribute('aria-label', 'Fermer');
        closeButton.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
        viewer.appendChild(closeButton);

        name = doc.createElement('p');
        name.className = 'position-absolute top-0 start-0 m-3 me-5 text-white-50 small text-break mb-0';
        viewer.appendChild(name);

        var stage = doc.createElement('div');
        stage.className = 'file-viewer__stage d-flex align-items-center justify-content-center h-100 w-100 p-3';

        image = doc.createElement('img');
        image.className = 'file-viewer__content d-none';
        image.alt = '';
        stage.appendChild(image);

        // The case « ça ne se regarde pas ici » : said, and followed by an
        // action. A PDF is not rendered — an iframe of one does not render
        // reliably in a standalone iOS window, and a viewer showing an
        // empty frame is worse than a plain button.
        fallback = doc.createElement('div');
        fallback.className = 'file-viewer__content d-none text-center text-white';
        fallback.innerHTML = '<i class="bi bi-file-earmark-arrow-down fs-1" aria-hidden="true"></i>';
        fallbackText = doc.createElement('p');
        fallbackText.className = 'mt-2 mb-0';
        fallback.appendChild(fallbackText);
        stage.appendChild(fallback);

        viewer.appendChild(stage);

        var actions = doc.createElement('div');
        actions.className = 'position-absolute bottom-0 start-50 translate-middle-x mb-3';
        download = doc.createElement('a');
        download.className = 'btn btn-sm btn-outline-light';
        download.href = '#';

        if (isStandalone()) {
            // **Not a download, and not `target="_blank"` either.** Both
            // are handled inside the standalone window on iOS, which is
            // how the viewer itself would strand the app it exists to
            // protect. `window.open()` from a standalone web app launches
            // the BROWSER — a separate application — so ScoutMagic is
            // still there, untouched, one swipe away in the app switcher.
            download.textContent = 'Ouvrir dans le navigateur';
            download.addEventListener('click', function (event) {
                event.preventDefault();
                win.open(download.href, '_blank');
            });
        } else {
            download.setAttribute('download', '');
            download.setAttribute('target', '_blank');
            download.setAttribute('rel', 'noopener');
            download.textContent = 'Enregistrer';
        }

        actions.appendChild(download);
        viewer.appendChild(actions);

        doc.body.appendChild(viewer);

        closeButton.addEventListener('click', close);
        viewer.addEventListener('click', function (event) {
            // The backdrop closes; the image and the buttons do not.
            if (event.target === viewer) {
                close();
            }
        });
    }

    function close() {
        if (!viewer) {
            return;
        }
        viewer.classList.add('d-none');
        // Emptied on close: a large photo left in the DOM is a large photo
        // held in memory, and the next open would flash the previous one.
        image.src = '';
        image.classList.add('d-none');
        fallback.classList.add('d-none');
        doc.body.style.overflow = '';
    }

    /** What the overlay shows when the file is not something it can draw. */
    function showFallback(label) {
        image.classList.add('d-none');
        fallbackText.textContent = label
            ? 'Ce fichier ne s’affiche pas ici : ' + label
            : 'Ce fichier ne s’affiche pas ici.';
        fallback.classList.remove('d-none');
    }

    /**
     * Show one file, without navigating to it.
     *
     * **The type is discovered by trying, not declared.** A caller that
     * knows says so (`data-file-image`), but the click handler below now
     * catches every file link in the installed app, and those links carry
     * nothing: a mailbox attachment is `/files/512` and no more. Rather
     * than a HEAD request per click to learn the content type, the
     * overlay simply asks the `<img>` to draw it — which is the request
     * it would make anyway — and falls back when the browser says it
     * cannot. A wrong guess costs one failed image load; the alternative
     * costs a round trip on every single click.
     *
     * @param {string} href
     * @param {string} label
     * @param {boolean|null} known true/false when a caller declared the
     *        type, null when it is to be discovered
     */
    function open(href, label, known) {
        if (!viewer) {
            build();
        }

        name.textContent = label;
        download.href = href;
        // The name rides on `download` only where that attribute is safe.
        // In the installed app it is the trap itself — setting it here
        // would put the viewer's own button back on Safari's download
        // screen, which is the screen this whole file exists to avoid.
        if (label && !isStandalone()) {
            download.setAttribute('download', label);
        }

        if (known === false) {
            // Said rather than shown badly: an iframe of a PDF does not
            // render reliably in a standalone iOS window, and a viewer
            // showing an empty frame is worse than a plain button.
            showFallback(label);
        } else {
            image.onerror = function () {
                showFallback(label);
            };
            image.src = href;
            image.alt = label;
            image.classList.remove('d-none');
            fallback.classList.add('d-none');
        }

        viewer.classList.remove('d-none');
        // The page behind must not scroll under the overlay on a phone.
        doc.body.style.overflow = 'hidden';
        closeButton.focus();
    }

    /**
     * The name to show, from whatever the link could tell us.
     *
     * @param {HTMLAnchorElement} link
     */
    function labelOf(link) {
        return link.getAttribute('data-file-name')
            || link.getAttribute('download')
            || (link.textContent || '').trim();
    }

    // Delegated, so a link rendered after load works too.
    doc.addEventListener('click', function (event) {
        var target = /** @type {Element} */ (event.target);
        if (!target || !target.closest) {
            return;
        }

        var declared = /** @type {HTMLElement|null} */ (target.closest('[data-file-viewer]'));
        if (declared) {
            event.preventDefault();
            open(
                declared.getAttribute('data-file-viewer') || '',
                declared.getAttribute('data-file-name') || '',
                declared.getAttribute('data-file-image') === '1' ? true : false
            );
            return;
        }

        // **In the installed app, EVERY file link is intercepted.** Not
        // decorated — intercepted: on iOS neither `download` nor
        // `target="_blank"` keeps the window still, and both land on
        // Safari's own download screen inside it, which has no back
        // button either. The only thing that cannot strand the app is a
        // click that never navigates.
        if (!isStandalone()) {
            return;
        }
        //
        // Two shapes are caught: this application's own file route, and
        // ANY same-origin link already carrying `download`. The second is
        // not a guess — in the installed app that attribute is precisely
        // what puts iOS on its download screen, so a link wearing it is a
        // link that would strand the window. It is what the gallery's own
        // « Télécharger en haute qualité » wears, and what every link the
        // browser-tab net decorates wears.
        var link = /** @type {HTMLAnchorElement|null} */ (
            target.closest('a[href^="/files/"], a[download]')
        );
        if (!link || link.hasAttribute('data-file-link-raw')) {
            return;
        }
        // Same origin only: `download` on somebody else's page is ignored
        // by the browser anyway, and intercepting it would be this script
        // deciding where an external link goes.
        var href = link.getAttribute('href') || '';
        if (href === '' || href === '#' || /^[a-z][a-z0-9+.-]*:/i.test(href)) {
            return;
        }

        event.preventDefault();
        open(link.href, labelOf(link), null);
    });

    doc.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && viewer && !viewer.classList.contains('d-none')) {
            close();
        }
    });
})();
