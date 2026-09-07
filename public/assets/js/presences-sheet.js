/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The attendance sheet (modules/presences/views/sheet.html.twig).
//
// This screen is filled in standing up, in a local, at the start of a
// meeting — which is what decides everything below. Four visible targets
// per animé rather than a select (a select is two gestures per name, fifty
// for twenty-five names, in the noise); every tap saved on the spot rather
// than a « Enregistrer » button at the foot of a list somebody would
// forget one time in three; and the four counters double as the filter,
// because the real loop is « point as they arrive, then tap Non renseigné
// and deal with what is left ».
//
// A save that fails is never silent and never leaves the screen claiming
// something the server does not have: the button goes back to the state
// the server still holds and a toast says so (design.md §7.5 — never an
// alert()).
(function () {
    'use strict';

    var api = window.ScoutMagicApi;

    var container = /** @type {HTMLElement|null} */ (document.getElementById('presences-lines'));
    var data = api ? api.pageData('presences-sheet-data') : null;

    // A no-op on every other page of the site.
    if (!container || !data?.endpoint) {
        return;
    }

    var COMMENT_DEBOUNCE_MS = 600;

    var counters = /** @type {NodeListOf<HTMLElement>} */ (
        document.querySelectorAll('#presences-counters .presence-filter')
    );
    var filterBar = /** @type {HTMLElement|null} */ (document.getElementById('presences-filter-bar'));
    var filterSummary = /** @type {HTMLElement|null} */ (document.getElementById('presences-filter-summary'));
    var clearFilter = /** @type {HTMLElement|null} */ (document.getElementById('presences-clear-filter'));

    /** @type {string|null} the status currently filtered on, null for « tout » */
    var activeFilter = null;

    /**
     * @returns {HTMLElement[]} one element per animé on the sheet
     */
    function lines() {
        return Array.prototype.slice.call(container.querySelectorAll('.presence-line'));
    }

    /**
     * Paint one line's four buttons for the state it is now in. The
     * chosen one is filled with its own colour; the other three are
     * outlines — a fill is what can be read on a phone held at arm's
     * length, where a border cannot.
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
     * One save. Resolves to whether the server recorded it — the caller
     * is what puts the screen back when it did not.
     *
     * @param {string} memberId
     * @param {Record<string, any>} payload `status` XOR `comment`: two
     *        animateurs pointing the same list must not have one's tap
     *        erase the other's comment.
     * @returns {Promise<boolean>}
     */
    function save(memberId, payload) {
        return api.postJson(data.endpoint, { member_id: Number(memberId), ...payload })
            .then(function (res) {
                if (res.data?.success) {
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

            save(line.dataset.memberId || '', { status: chosen }).then(function (recorded) {
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
    // The debounce is PER ANIMÉ, not per page: one shared timer would let
    // a comment typed on the second name cancel the pending save of the
    // first, and nothing on screen would say the first was lost.
    /** @type {Record<string, number>} */
    var pendingComments = {};

    /**
     * @param {HTMLTextAreaElement} field
     */
    function scheduleCommentSave(field) {
        var memberId = field.dataset.memberId || '';
        clearTimeout(pendingComments[memberId]);
        pendingComments[memberId] = setTimeout(function () {
            delete pendingComments[memberId];
            save(memberId, { comment: field.value });
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

        var memberId = field.dataset.memberId || '';
        clearTimeout(pendingComments[memberId]);
        delete pendingComments[memberId];
        save(memberId, { comment: field.value });
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
