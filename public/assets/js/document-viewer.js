/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The buttons of the viewer the installed application gets instead of a
// file (core/View/templates/document_viewer.html.twig, issue #502). The
// page works without this script — three plain links — but two of them
// only do the right thing with it.
//
// « Ouvrir dans le navigateur »: window.open(), which must launch the
// phone's browser, a separate application, and leave this window where it
// is. The address was minted with the page: iOS refuses a window.open()
// that follows a wait, so nothing is asked of the server at the moment of
// the tap. The address works once, so the button is spent after it.
//
// **On an iPhone or iPad, a plain address does not leave the app** (issue
// #684). The manifest's scope is the whole site (PwaController, 'scope' =>
// '/'), and iOS opens an address inside that scope in the installed app's
// own window — where /document/{key}, which InstalledAppFileInterceptor
// lets through on purpose, lands the raw file with no way back. So there,
// and only there, the address is handed over as `x-safari-https://…`, the
// scheme iOS 17+ answers by launching Safari. Where iOS does not know it,
// it refuses the address and this window stays put: a failure that strands
// nobody.
//
// « Télécharger »: in the installed app on an iPhone, the share sheet
// (Enregistrer dans Fichiers, Imprimer, Mail…) — the only way to save a
// file there that does not strand the window on Safari's download screen.
// navigator.share() also needs the tap it answers, so the file is fetched
// when the page loads and handed over at the tap. Anywhere else — Android,
// a computer, a browser that cannot share files — the link is a real
// download and this script leaves it alone.
//
// « Retour »: the previous page in history, which after a form is the
// form. The link's own address (the referring page) is the fallback when
// there is no history to go back to.
(function () {
    'use strict';

    var win = window;
    var doc = document;
    var nav = /** @type {any} */ (win.navigator);

    var root = doc.querySelector('[data-document-viewer]');
    if (!root) {
        return;
    }

    var status = /** @type {HTMLElement|null} */ (root.querySelector('[data-document-viewer-status]'));

    /** @param {string} text */
    function say(text) {
        if (!status) {
            return;
        }
        status.textContent = text;
        status.classList.remove('d-none');
    }

    function isStandalone() {
        return (typeof win.matchMedia === 'function' && win.matchMedia('(display-mode: standalone)').matches)
            || nav.standalone === true;
    }

    function isAppleMobile() {
        return /iPad|iPhone|iPod/.test(nav.userAgent || '')
            || (nav.platform === 'MacIntel' && Number(nav.maxTouchPoints) > 1);
    }

    /**
     * The address « Ouvrir dans le navigateur » hands to window.open().
     *
     * Checked here, at the sink, and not trusted for having come from a
     * template: only an address of this very site, over http(s), is ever
     * rewritten to the Safari scheme — anything else is returned as the
     * link already said, exactly as before this existed.
     *
     * @param {HTMLAnchorElement} link
     * @returns {string}
     */
    function browserAddress(link) {
        if (!(isStandalone() && isAppleMobile())) {
            return link.href;
        }
        var url;
        try {
            url = new win.URL(link.href, win.location.href);
        } catch {
            return link.href;
        }
        if (url.origin !== win.location.origin || (url.protocol !== 'https:' && url.protocol !== 'http:')) {
            return link.href;
        }
        return 'x-safari-' + url.href;
    }

    var browserLink = /** @type {HTMLAnchorElement|null} */ (root.querySelector('[data-document-viewer-browser]'));
    if (browserLink) {
        var opener = browserLink;
        opener.addEventListener('click', function (event) {
            event.preventDefault();
            if (opener.classList.contains('disabled')) {
                return;
            }
            win.open(browserAddress(opener), '_blank');
            if ('documentViewerReusable' in opener.dataset) {
                // The page's own public address, not a one-use key.
                return;
            }
            opener.classList.add('disabled');
            opener.setAttribute('aria-disabled', 'true');
            say('Le fichier s’est ouvert dans votre navigateur. Ce lien ne sert qu’une fois.');
        });
    }

    var downloadLink = /** @type {HTMLAnchorElement|null} */ (root.querySelector('[data-document-viewer-download]'));
    if (downloadLink && isStandalone() && isAppleMobile() && typeof nav.share === 'function'
        && typeof nav.canShare === 'function' && typeof win.File === 'function') {
        var saver = downloadLink;
        /** @type {File|null} */
        var file = null;
        var failed = false;

        win.fetch(saver.href, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.blob();
            })
            .then(function (blob) {
                var candidate = new win.File([blob], saver.dataset.fileName || 'document', {
                    type: saver.dataset.fileType || blob.type,
                });
                if (nav.canShare({ files: [candidate] })) {
                    file = candidate;
                } else {
                    failed = true;
                }
            })
            .catch(function () {
                failed = true;
            });

        saver.addEventListener('click', function (event) {
            if (failed) {
                // Nothing to share: the plain download is what is left.
                return;
            }
            event.preventDefault();
            if (!file) {
                say('Le fichier se prépare, réessayez dans un instant.');
                return;
            }
            nav.share({ files: [file], title: file.name }).catch(function (/** @type {any} */ error) {
                if (error?.name !== 'AbortError') {
                    say('Le partage n’a pas pu s’ouvrir. Utilisez « Ouvrir dans le navigateur ».');
                }
            });
        });
    }

    var backLink = /** @type {HTMLAnchorElement|null} */ (root.querySelector('[data-document-viewer-back]'));
    if (backLink) {
        backLink.addEventListener('click', function (event) {
            if (win.history.length > 1) {
                event.preventDefault();
                win.history.back();
            }
        });
    }
})();
