/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The register's one search field (modules/presences/views/index.html.twig).
//
// ONE field for two kinds of thing — the section's evenings and its
// animés — because somebody types what they have rather than choosing a
// mode first; the results are grouped by nature rather than mixed, so the
// answer is still readable. Same shape as the receipt-to-movement picker
// in finance: type, wait a beat, ask the server, click a result.
//
// It searches server-side rather than filtering in the browser: the
// animés' names are encrypted at rest and only ever decrypted there, so
// there is no list here to filter in the first place.
//
// The empty field ALREADY answers, with the most recent evenings and a
// first few animés. A panel that opened empty would read as « you must
// know a syntax ».
(function () {
    'use strict';

    var api = window.ScoutMagicApi;

    var container = /** @type {HTMLElement|null} */ (document.getElementById('presences-search'));
    var input = /** @type {HTMLInputElement|null} */ (document.getElementById('presences-search-input'));
    var panel = /** @type {HTMLElement|null} */ (document.getElementById('presences-search-results'));

    // A no-op on every other page of the site.
    if (!api || !container || !input || !panel) {
        return;
    }

    var DEBOUNCE_MS = 250;
    var sectionId = container.dataset.sectionId || '';

    function close() {
        panel.classList.add('d-none');
        panel.replaceChildren();
    }

    /**
     * @param {string} label
     */
    function heading(label) {
        var element = document.createElement('p');
        element.className = 'list-group-item bg-body-tertiary small text-body-secondary text-uppercase mb-0 py-1';
        element.textContent = label;
        return element;
    }

    /**
     * One result row. Built with createElement/textContent rather than
     * innerHTML: an event title and an animé's name are content somebody
     * typed, and a row assembled as an HTML string is how that becomes a
     * script tag (AGENTS.md § CodeQL).
     *
     * @param {string} url
     * @param {string} label
     * @param {string} trailing
     * @param {string} [tone]
     */
    function row(url, label, trailing, tone) {
        var link = document.createElement('a');
        link.href = url;
        link.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2';

        var name = document.createElement('span');
        name.className = 'flex-grow-1 text-truncate';
        name.textContent = label;
        link.appendChild(name);

        var badge = document.createElement('span');
        badge.className = 'flex-shrink-0 small' + (tone ? ' fw-semibold text-' + tone : ' text-body-secondary');
        badge.textContent = trailing;
        link.appendChild(badge);

        return link;
    }

    /**
     * @param {string} stored a `YYYY-MM-DD` date as the column holds it
     */
    function frenchDate(stored) {
        var parts = String(stored).split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : String(stored);
    }

    /**
     * @param {{events: any[], animes: any[]}} results
     */
    function render(results) {
        panel.replaceChildren();

        var events = results.events || [];
        var animes = results.animes || [];

        if (events.length === 0 && animes.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'list-group-item text-body-secondary mb-0';
            empty.textContent = 'Aucun résultat.';
            panel.appendChild(empty);
            panel.classList.remove('d-none');
            return;
        }

        if (events.length > 0) {
            panel.appendChild(heading('Évènements'));
            events.forEach(function (event) {
                panel.appendChild(row(
                    event.url,
                    event.title + ' — ' + frenchDate(event.date),
                    event.pointed ? event.rate + ' %' : 'non pointé'
                ));
            });
        }

        if (animes.length > 0) {
            panel.appendChild(heading('Animés'));
            animes.forEach(function (anime) {
                panel.appendChild(row(anime.url, anime.name, anime.rate + ' %', anime.tone));
            });
        }

        panel.classList.remove('d-none');
    }

    function run() {
        var url = '/chefs/presences/recherche?section=' + encodeURIComponent(sectionId)
            + '&q=' + encodeURIComponent(input.value);

        return api.getJson(url).then(function (res) {
            if (!res.data || !res.data.success) {
                close();
                return;
            }
            render(res.data);
        });
    }

    input.addEventListener('input', api.debounce(run, DEBOUNCE_MS));
    input.addEventListener('focus', function () {
        if (panel.classList.contains('d-none')) {
            run();
        }
    });

    // A panel floating over the page has to be closable without picking
    // anything, or it sits on top of what is underneath it.
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            close();
        }
    });
    document.addEventListener('click', function (event) {
        if (!container.contains(/** @type {Node} */ (event.target))) {
            close();
        }
    });
})();
