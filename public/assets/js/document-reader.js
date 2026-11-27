/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// A document to read, read without leaving the form (issue #1431).
//
// In the installed application a link to this site's own pages stays in
// the window whatever `target="_blank"` says — iOS ignores it there — and
// the window has no back button. A renter who taps « conditions de
// location » halfway through a booking request lands on the conditions
// and loses everything they had typed. installed-app-links.js cannot help:
// it hands OTHER sites to the phone's browser, and lets this site's
// addresses through on purpose.
//
// So a link that says it is a document to read — `data-document-reader`
// on a real anchor, with its canonical address and `target="_blank"` —
// opens here, in the one shared dialog of base.html.twig
// (#document-reader), when this window is the installed app. In a browser
// tab nothing is intercepted: `target="_blank"` opens a new tab, which is
// exactly right there. The decision is the display mode, never the screen
// size or the device.
//
// What is shown is the canonical page's own content, not a copy: the page
// is fetched at the link's address — the exact archived version for the
// rental conditions, since that address carries it — and only the element
// its template marks `data-document-reader-content` is taken. The rest of
// the page (header, navigation, scripts, forms) is never shown. The markup
// is parsed by DOMParser, which runs nothing, and then stripped of what a
// document to read has no use for before it enters the page.
//
// Reading is not agreeing: opening or closing the dialog never touches the
// checkbox the link sits next to. The click is cancelled before a <label>
// could pass it on.
(function () {
    'use strict';

    var win = window;
    var doc = document;

    var DIALOG_ID = 'document-reader';
    var CONTENT_ATTRIBUTE = 'data-document-reader-content';
    // What a document to read never needs, and what could act on the page
    // if it came along: anything that runs, loads, submits or restyles.
    // svg and math carry links of their own (xlink:href) that nothing
    // below would see, and a document to read has no use for either.
    var REMOVED_ELEMENTS = 'script, style, link, meta, base, iframe, frame, object, embed, form, input, button, select, textarea, template, noscript, svg, math';
    // The schemes a link or an image may keep — an allowlist, never a
    // list of dangerous ones, for the reason Core\Security\HtmlSanitizer
    // gives: a blocklist always misses one (#1444 review). The value is
    // resolved by the URL parser, which strips the tabs, newlines and
    // leading control characters a browser ignores, so « java\tscript: »
    // is judged as the javascript: it would become.
    var SAFE_LINK_SCHEMES = ['http:', 'https:', 'mailto:', 'tel:'];
    // Attributes that would load or run something and that a document to
    // read never needs; whatever they hold, they go.
    var REMOVED_ATTRIBUTES = ['srcset', 'action', 'formaction', 'background', 'poster', 'ping', 'style', 'id'];

    /**
     * Whether a link or image address may stay in the document.
     *
     * @param {string} name href or src
     * @param {string} value as written
     * @returns {boolean}
     */
    function isSafeUrl(name, value) {
        var url;
        try {
            url = new URL(value, doc.location.href);
        } catch (e) {
            return false;
        }
        if (SAFE_LINK_SCHEMES.indexOf(url.protocol) !== -1) {
            return true;
        }
        // An image may be inline; an img never runs what it shows.
        return name === 'src' && url.protocol === 'data:' && /^data:image\//i.test(url.href);
    }

    /** @type {HTMLAnchorElement|null} the link the open dialog came from */
    var openedFrom = null;
    /** @type {AbortController|null} */
    var pending = null;

    function isStandalone() {
        var mode = /** @type {any} */ (win).ScoutMagicDisplayMode;
        if (mode && typeof mode.isStandalone === 'function') {
            return mode.isStandalone();
        }
        return (typeof win.matchMedia === 'function' && win.matchMedia('(display-mode: standalone)').matches)
            || /** @type {any} */ (win.navigator).standalone === true;
    }

    /**
     * The content element of a fetched page, made safe to show here.
     *
     * @param {string} html the whole page as the server sent it
     * @returns {{title: string, nodes: Node[]}|null} null when the page has no marked content
     */
    function extract(html) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var content = parsed.querySelector('[' + CONTENT_ATTRIBUTE + ']');
        if (!content) {
            return null;
        }
        var title = content.getAttribute('data-document-reader-title') || '';

        content.querySelectorAll(REMOVED_ELEMENTS).forEach(function (el) { el.remove(); });
        content.querySelectorAll('*').forEach(function (el) {
            Array.prototype.slice.call(el.attributes).forEach(function (attr) {
                var name = attr.name.toLowerCase();
                // Event handlers; namespaced attributes (xlink:href and
                // the like); and ids: the page this lands on has its own,
                // and a second #rgpd-last-updated would answer its
                // getElementById() calls in its place.
                if (name.indexOf('on') === 0 || name.indexOf(':') !== -1 || REMOVED_ATTRIBUTES.indexOf(name) !== -1) {
                    el.removeAttribute(attr.name);
                } else if ((name === 'href' || name === 'src') && !isSafeUrl(name, attr.value)) {
                    el.removeAttribute(attr.name);
                }
            });
            if (el.tagName === 'A' && el.hasAttribute('href')) {
                // A link in the document is the reader's choice to go
                // somewhere else. The attribute is only a declaration:
                // the installed window ignores it for this site's own
                // pages, so the click handler below is what keeps such a
                // link from replacing the form (#1444 review).
                el.setAttribute('target', '_blank');
                el.setAttribute('rel', 'noopener');
            }
        });

        return {
            title: title,
            nodes: Array.prototype.slice.call(content.childNodes).map(function (node) { return doc.importNode(node, true); }),
        };
    }

    /** @returns {HTMLElement|null} */
    function dialog() {
        return doc.getElementById(DIALOG_ID);
    }

    function canOpen() {
        return !!dialog() && typeof bootstrap !== 'undefined' && typeof win.fetch === 'function'
            && typeof DOMParser !== 'undefined';
    }

    /**
     * @param {HTMLElement} body
     * @param {HTMLAnchorElement} link
     */
    function showError(body, link) {
        body.replaceChildren();
        var alert = doc.createElement('div');
        alert.className = 'alert alert-warning';
        alert.setAttribute('role', 'alert');
        alert.textContent = 'Ce document n’a pas pu être affiché. Votre saisie est conservée.';
        body.appendChild(alert);

        var actions = doc.createElement('div');
        actions.className = 'd-flex flex-wrap gap-2';
        var retry = doc.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-primary';
        retry.setAttribute('data-document-reader-retry', '');
        retry.textContent = 'Réessayer';
        retry.addEventListener('click', function () { void load(link); });
        actions.appendChild(retry);

        var separate = doc.createElement('a');
        separate.className = 'btn btn-outline-secondary';
        separate.href = link.href;
        separate.target = '_blank';
        separate.rel = 'noopener';
        separate.textContent = 'Ouvrir à part';
        actions.appendChild(separate);
        body.appendChild(actions);
    }

    /** @param {HTMLAnchorElement} link */
    function load(link) {
        var el = dialog();
        var body = /** @type {HTMLElement} */ (el.querySelector('[data-document-reader-body]'));
        var titleEl = el.querySelector('.modal-title');
        if (pending) {
            pending.abort();
        }
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        pending = controller;

        body.setAttribute('aria-busy', 'true');
        body.replaceChildren();
        var loading = doc.createElement('p');
        loading.className = 'text-body-secondary';
        loading.setAttribute('role', 'status');
        loading.textContent = 'Chargement…';
        body.appendChild(loading);

        return win.fetch(link.href, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
            signal: controller ? controller.signal : undefined,
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        }).then(function (html) {
            if (pending !== controller) {
                return;
            }
            var result = extract(html);
            if (!result) {
                throw new Error('no document content');
            }
            if (result.title) {
                titleEl.textContent = result.title;
            }
            body.replaceChildren.apply(body, result.nodes);
            var scroller = body.closest('.modal-body');
            if (scroller) {
                scroller.scrollTop = 0;
            }
        }).catch(function (error) {
            if (pending !== controller || (error && error.name === 'AbortError')) {
                return;
            }
            showError(body, link);
        }).finally(function () {
            if (pending === controller) {
                body.removeAttribute('aria-busy');
                pending = null;
            }
        });
    }

    /** @param {HTMLAnchorElement} link */
    function open(link) {
        var el = dialog();
        var titleEl = el.querySelector('.modal-title');
        titleEl.textContent = link.getAttribute('data-document-reader') || link.textContent.trim();
        openedFrom = link;
        bootstrap.Modal.getOrCreateInstance(el).show();
        return load(link);
    }

    doc.addEventListener('shown.bs.modal', function (event) {
        if (event.target !== dialog()) {
            return;
        }
        // The title, not the close button: a screen reader starts with
        // what is being read, and the first Tab still reaches « Fermer ».
        var titleEl = /** @type {HTMLElement} */ (dialog().querySelector('.modal-title'));
        titleEl.setAttribute('tabindex', '-1');
        titleEl.focus();
    });

    doc.addEventListener('hidden.bs.modal', function (event) {
        if (event.target !== dialog()) {
            return;
        }
        if (pending) {
            pending.abort();
            pending = null;
        }
        var link = openedFrom;
        openedFrom = null;
        if (link && link.isConnected) {
            link.focus();
        }
    });

    /**
     * The link's address when it stays on this site over http(s), else null.
     *
     * @param {HTMLAnchorElement} link
     * @returns {URL|null}
     */
    function sameOrigin(link) {
        var url;
        try {
            url = new URL(link.href, doc.location.href);
        } catch (e) {
            return null;
        }
        if ((url.protocol !== 'http:' && url.protocol !== 'https:') || url.origin !== doc.location.origin) {
            return null;
        }
        return url;
    }

    // Capture phase: before a <label> or another listener on the page can
    // act on the click. offline-nav.js, also capturing and registered
    // earlier, may already have answered it — then it is left alone.
    doc.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey
            || event.shiftKey || event.altKey || !isStandalone()) {
            return;
        }
        var target = /** @type {Element|null} */ (event.target);
        if (!target || !target.closest) {
            return;
        }
        var link = /** @type {HTMLAnchorElement|null} */ (target.closest('a[href]'));
        if (!link) {
            return;
        }
        var url = sameOrigin(link);
        if (!url) {
            // Another site: installed-app-links.js hands it to the
            // phone's browser, from here as from anywhere else.
            return;
        }

        // A link INSIDE the open dialog — one the document itself carries,
        // like the notice's « page de préférences cookies », or « Ouvrir à
        // part » after a failure. Followed here it would replace the form
        // behind the dialog, `target="_blank"` notwithstanding, so it goes
        // to a window of its own, the way installed-app-links.js sends
        // another site to the browser.
        var el = dialog();
        if (el && el.contains(link)) {
            event.preventDefault();
            event.stopPropagation();
            win.open(url.href, '_blank', 'noopener');
            return;
        }

        if (!link.hasAttribute('data-document-reader') || !canOpen()) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        void open(link);
    }, true);
})();
