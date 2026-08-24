/*! ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 *  Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE. */

// Contextual help panel (partials/help_panel.html.twig — ARCHITECTURE.md
// §8.64). Opening and closing belong to Bootstrap's own offcanvas
// machinery (the button carries data-bs-toggle); this script only exists
// for the multi-topic case: when several topics cover one page the panel
// lands on a title+summary list, a tap shows that topic INSIDE the same
// panel, and a back control returns to the list. It also keeps the
// footer's « Ouvrir dans l'aide » link pointing at whichever topic is
// visible. Every topic's content is already server-rendered in the panel
// — nothing here ever fetches anything, so the panel works offline.
//
// No inline on*= handlers (dead under the CSP — design.md §7.5), no
// alert()/confirm().
(function () {
    var panel = document.getElementById('help-panel');
    if (!panel) {
        return;
    }

    var list = /** @type {HTMLElement|null} */ (panel.querySelector('[data-help-list]'));
    var fullLink = /** @type {HTMLAnchorElement|null} */ (panel.querySelector('[data-help-open-full]'));
    var topics = panel.querySelectorAll('[data-help-topic]');

    // Mirrors the id format HelpFrontMatterParser itself enforces server-side
    // (core/Help/HelpFrontMatterParser.php: "lowercase letters, digits and
    // dashes only"). topicId is read from a DOM attribute, so building a URL
    // from it needs an explicit allowlist check first — a bare concatenation
    // is exactly the DOM-text-reinterpreted-as-a-URL pattern CodeQL's
    // js/xss-through-dom flags, however the attribute itself was populated.
    var TOPIC_ID_PATTERN = /^[a-z0-9-]+$/;

    /** @param {string} topicId */
    function showTopic(topicId) {
        topics.forEach(function (article) {
            article.classList.toggle('d-none', article.getAttribute('data-help-topic') !== topicId);
        });
        if (list) {
            list.classList.add('d-none');
        }
        if (fullLink) {
            fullLink.href = TOPIC_ID_PATTERN.test(topicId) ? '/aide/' + topicId : '/aide';
            fullLink.classList.remove('invisible');
        }
        // The list view may have been scrolled — a freshly opened topic
        // starts at its own top.
        var body = panel.querySelector('.offcanvas-body');
        if (body) {
            body.scrollTop = 0;
        }
    }

    function showList() {
        if (!list) {
            return;
        }
        topics.forEach(function (article) {
            article.classList.add('d-none');
        });
        list.classList.remove('d-none');
        if (fullLink) {
            // The list view names no single topic, so the footer link has
            // no meaningful target — invisible (not d-none) keeps the
            // footer's layout stable.
            fullLink.classList.add('invisible');
        }
    }

    panel.addEventListener('click', function (e) {
        if (!(e.target instanceof Element)) {
            return;
        }

        var opener = e.target.closest('[data-help-open]');
        if (opener) {
            showTopic(opener.getAttribute('data-help-open') || '');
            return;
        }

        if (e.target.closest('[data-help-back]')) {
            showList();
        }
    });
})();
