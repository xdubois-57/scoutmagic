/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The presence rows, on both screens that carry them: an evening's sheet
// (modules/presences/views/sheet.html.twig, one row per animé) and an
// animé's own page (views/anime.html.twig, one row per date). The markup
// is literally the same partial, so the behaviour is one file rather than
// two that drift.
//
// The sheet is what decides everything below, because it is filled in
// standing up, in a local, at the start of a meeting. Four visible targets
// per row rather than a select (a select is two gestures per name, fifty
// for twenty-five names, in the noise); every tap saved on the spot rather
// than a « Enregistrer » button at the foot of a list somebody would
// forget one time in three; and the four counters double as the filter,
// because the real loop is « point as they arrive, then tap Non renseigné
// and deal with what is left ». The counters are the sheet's alone — an
// animé's page has no filter to offer and simply does not draw them, which
// is why everything about them is optional here.
//
// **Each row carries its own endpoint**, rather than the page carrying
// one: a sheet writes every row to the evening it is, an animé's page
// writes each row to the evening THAT date is — the same endpoint that
// date's own sheet writes to, so there is one write path and one
// authorization check for both screens.
//
// A save that fails is never silent and never leaves the screen claiming
// something the server does not have: the button goes back to the state
// the server still holds and a toast says so (design.md §7.5 — never an
// alert()).
(function () {
    'use strict';

    var api = window.ScoutMagicApi;

    var container = /** @type {HTMLElement|null} */ (document.getElementById('presences-lines'));

    // A no-op on every other page of the site.
    if (!container || !api) {
        return;
    }

    var COMMENT_DEBOUNCE_MS = 600;

    var counters = /** @type {NodeListOf<HTMLElement>} */ (
        document.querySelectorAll('#presences-counters .presence-filter')
    );
    var filterBar = /** @type {HTMLElement|null} */ (document.getElementById('presences-filter-bar'));
    var filterSummary = /** @type {HTMLElement|null} */ (document.getElementById('presences-filter-summary'));
    var clearFilter = /** @type {HTMLElement|null} */ (document.getElementById('presences-clear-filter'));
    // An animé's page draws its rate, its monthly slope and its counters
    // server-side, and nothing here recomputes them — so a page whose rows
    // have moved says so rather than going on showing a figure that is no
    // longer true. Absent from the sheet, which has no such figures.
    var staleNotice = /** @type {HTMLElement|null} */ (document.getElementById('presences-stale'));

    /** @type {string|null} the status currently filtered on, null for « tout » */
    var activeFilter = null;

    /**
     * @returns {HTMLElement[]} one element per row on the page
     */
    function lines() {
        return Array.prototype.slice.call(container.querySelectorAll('.presence-line'));
    }

    /**
     * Paint one row's four buttons for the state it is now in. The chosen
     * one is filled with its own colour; the other three are outlines — a
     * fill is what can be read on a phone held at arm's length, where a
     * border cannot.
     *
     * @param {HTMLElement} line
     * @param {string} status
     */
    function paintLine(line, status) {
        line.dataset.status = status;

        line.querySelectorAll('.presence-state').forEach(function (element) {
            var button = /** @type {HTMLButtonElement} */ (element);
            var tone = button.dataset.tone || 'secondary';
            var chosen = button.dataset.status === status;

            button.classList.toggle('btn-' + tone, chosen);
            button.classList.toggle('btn-outline-secondary', !chosen);
            button.setAttribute('aria-pressed', chosen ? 'true' : 'false');
        });
    }

    /**
     * Recount the four tiles from the DOM — never from a running total,
     * which would drift the first time a save is refused and put back.
     *
     * A tile at zero is dimmed and inert: there is nothing to filter on,
     * and offering the gesture anyway produces an empty list that reads
     * as a bug.
     */
    function refreshCounters() {
        var all = lines();

        counters.forEach(function (element) {
            var tile = /** @type {HTMLButtonElement} */ (element);
            var status = tile.dataset.status;
            var count = all.filter(function (line) {
                return line.dataset.status === status;
            }).length;

            var label = tile.querySelector('.presence-filter-count');
            if (label) {
                label.textContent = String(count);
            }

            var active = activeFilter === status;
            var tone = tile.dataset.tone || 'secondary';
            tile.classList.toggle('btn-' + tone, active);
            tile.classList.toggle('btn-outline-secondary', !active);
            tile.setAttribute('aria-pressed', active ? 'true' : 'false');
            tile.classList.toggle('opacity-50', count === 0 && !active);
            tile.disabled = count === 0 && !active;
        });
    }

    /**
     * Show only the animés in the filtered state, and say how many that
     * is — « 2 affichés sur 10 — tout revoir ». No tile can say that on
     * its own, and without it a filtered list looks like a short list.
     */
    function applyFilter() {
        var shown = 0;

        lines().forEach(function (line) {
            var visible = activeFilter === null || line.dataset.status === activeFilter;
            line.classList.toggle('d-none', !visible);
            if (visible) {
                shown++;
            }
        });

        if (filterBar) {
            filterBar.classList.toggle('d-none', activeFilter === null);
        }
        if (filterSummary) {
            filterSummary.textContent = shown + ' affiché' + (shown > 1 ? 's' : '')
                + ' sur ' + lines().length + ' — tout revoir';
        }

        refreshCounters();
    }

    /**
     * Only a path of this very site is ever posted to. The endpoint is
     * read off the row rather than written into this file, and a value
     * read from the DOM is not trusted for having been rendered by our own
     * template: `//ailleurs.example` is a URL to another host, not a path
     * on this one. Checked here, at the sink, rather than at each caller.
     *
     * **Spelling out the shapes that escape is a losing game, so this asks
     * the parser instead.** `//host`, `/\host` (WHATWG reads `\` as `/`
     * after a special scheme) and `/<TAB>/host` (tab, LF and CR are
     * stripped BEFORE parsing) all resolve to another origin while looking
     * like a path; a test written character by character closes the three
     * that somebody thought of. Resolving the value through the very
     * parser `fetch()` will use, and comparing the origin it lands on,
     * closes the class — every normalisation the parser performs is
     * performed here first, by definition.
     *
     * @param {string} url
     * @returns {boolean}
     */
    function isOwnPath(url) {
        // A path, not an absolute URL — same-origin or not, this endpoint
        // is written by a template as a path, and anything else is a
        // misconfiguration worth refusing.
        if (!url.startsWith('/')) {
            return false;
        }

        try {
            return new URL(url, document.baseURI).origin === window.location.origin;
        } catch (error) {
            return false;
        }
    }

    /**
     * Say that the page's server-computed figures no longer match its
     * rows. A no-op on a screen that has none.
     */
    function markStale() {
        if (staleNotice) {
            staleNotice.classList.remove('d-none');
        }
    }

    /**
     * One save. Resolves to whether the server recorded it — the caller
     * is what puts the screen back when it did not.
     *
     * @param {HTMLElement} line the row being saved, which carries both
     *        the animé and the evening the write belongs to
     * @param {Record<string, any>} payload `status` XOR `comment`: two
     *        animateurs pointing the same list must not have one's tap
     *        erase the other's comment.
     * @returns {Promise<boolean>}
     */
    function save(line, payload) {
        var endpoint = line.dataset.endpoint || '';
        if (!isOwnPath(endpoint)) {
            window.ScoutMagicToast.show('Erreur lors de l\'enregistrement.', { variant: 'error' });
            return Promise.resolve(false);
        }

        return api.postJson(endpoint, { member_id: Number(line.dataset.memberId || 0), ...payload })
            .then(function (res) {
                if (res.data?.success) {
                    markStale();
                    return true;
                }

                window.ScoutMagicToast.show(
                    res.status === 0
                        ? 'Erreur réseau : cet appui n\'a pas été enregistré.'
                        : res.data?.error || 'Erreur lors de l\'enregistrement.',
                    { variant: 'error' }
                );
                return false;
            });
    }

    container.addEventListener('click', function (event) {
        var target = /** @type {HTMLElement} */ (event.target);
        var button = /** @type {HTMLButtonElement|null} */ (target.closest('.presence-state'));

        if (button) {
            var line = /** @type {HTMLElement|null} */ (button.closest('.presence-line'));
            if (!line) {
                return;
            }

            var previous = line.dataset.status || 'unset';
            var chosen = button.dataset.status || 'unset';
            if (previous === chosen) {
                return;
            }

            // Optimistic: the animateur sees the tap land immediately,
            // and a refused save puts the line back where the server
            // still has it. A line that changes state under an active
            // filter LEAVES the list — that is the point of the filter,
            // and the note under the sheet says so.
            paintLine(line, chosen);
            applyFilter();

            save(line, { status: chosen }).then(function (recorded) {
                if (!recorded) {
                    paintLine(line, previous);
                    applyFilter();
                }
            });
            return;
        }

        var toggle = /** @type {HTMLElement|null} */ (target.closest('.presence-comment-toggle'));
        if (toggle) {
            var commentLine = /** @type {HTMLElement|null} */ (toggle.closest('.presence-line'));
            var panel = commentLine
                ? /** @type {HTMLElement|null} */ (commentLine.querySelector('.presence-comment-panel'))
                : null;
            if (!panel) {
                return;
            }

            panel.classList.remove('d-none');
            toggle.classList.add('d-none');

            var field = /** @type {HTMLTextAreaElement|null} */ (panel.querySelector('.presence-comment'));
            if (field) {
                field.focus();
            }
        }
    });

    // A comment saves as it is written, debounced, and again on blur —
    // an animateur who taps the next name without leaving the field must
    // not lose what they just typed.
    //
    // The debounce is PER ROW, not per page: one shared timer would let a
    // comment typed on the second row cancel the pending save of the
    // first, and nothing on screen would say the first was lost. The key
    // is the row's own id rather than the animé's, because every row of an
    // animé's page is the same animé on a different date.
    /** @type {Record<string, number>} */
    var pendingComments = {};

    /**
     * @param {HTMLTextAreaElement} field
     * @returns {HTMLElement|null} the row the comment belongs to
     */
    function rowOf(field) {
        return /** @type {HTMLElement|null} */ (field.closest('.presence-line'));
    }

    /**
     * Is this field still holding the text the page loaded, or the text
     * we last sent?
     *
     * Opening the comment panel focuses the field, so tapping a state
     * button afterwards raises a focusout on a comment nobody touched.
     * Posting it would re-encrypt and re-stamp the row for nothing —
     * and, worse, replace a comment a second animateur wrote since this
     * page loaded with the text this page still remembers. The whole
     * point of writing a state and a comment separately is not to
     * overwrite the other one's work.
     *
     * @param {HTMLTextAreaElement} field
     * @returns {boolean}
     */
    function isUnchanged(field) {
        return field.value === (field.dataset.savedComment !== undefined
            ? field.dataset.savedComment
            : field.defaultValue);
    }

    /**
     * **The text counts as saved only once the server says so.** Marking
     * it before the answer would make the next blur see an unchanged
     * field and skip the retry — so a comment the server refused would sit
     * on screen looking written, and be gone on reload. That is exactly
     * what this file's header forbids, and it is why the state path a few
     * lines above repaints on `!recorded` rather than trusting the tap.
     *
     * The value sent is captured now: somebody who keeps typing while the
     * request is in flight must not have their newer text marked saved by
     * the answer to the older one.
     *
     * @param {HTMLTextAreaElement} field
     * @returns {Promise<boolean>|undefined}
     */
    function saveComment(field) {
        var row = rowOf(field);
        if (!row || isUnchanged(field)) {
            return undefined;
        }

        var sent = field.value;

        return save(row, { comment: sent }).then(function (recorded) {
            if (recorded) {
                field.dataset.savedComment = sent;
            }
            return recorded;
        });
    }

    /**
     * @param {HTMLTextAreaElement} field
     * @returns {string} what keys this field's pending save
     */
    function debounceKeyOf(field) {
        var row = rowOf(field);

        return row ? (row.dataset.rowId || row.dataset.memberId || '') : '';
    }

    /**
     * @param {HTMLTextAreaElement} field
     */
    function scheduleCommentSave(field) {
        var key = debounceKeyOf(field);
        clearTimeout(pendingComments[key]);
        pendingComments[key] = setTimeout(function () {
            delete pendingComments[key];
            saveComment(field);
        }, COMMENT_DEBOUNCE_MS);
    }

    /**
     * @param {Event} event
     * @returns {HTMLTextAreaElement|null}
     */
    function commentFieldOf(event) {
        var target = /** @type {HTMLElement|null} */ (event.target);
        return target ? /** @type {HTMLTextAreaElement|null} */ (target.closest('.presence-comment')) : null;
    }

    container.addEventListener('input', function (event) {
        var field = commentFieldOf(event);
        if (field) {
            scheduleCommentSave(field);
        }
    });

    // Leaving the field saves at once, and drops the pending debounce so
    // the same text is not sent twice.
    container.addEventListener('focusout', function (event) {
        var field = commentFieldOf(event);
        if (!field) {
            return;
        }

        var key = debounceKeyOf(field);
        clearTimeout(pendingComments[key]);
        delete pendingComments[key];
        saveComment(field);
    }, true);

    counters.forEach(function (element) {
        element.addEventListener('click', function () {
            var status = /** @type {HTMLElement} */ (element).dataset.status || null;
            activeFilter = activeFilter === status ? null : status;
            applyFilter();
        });
    });

    if (clearFilter) {
        clearFilter.addEventListener('click', function () {
            activeFilter = null;
            applyFilter();
        });
    }

    refreshCounters();
})();
