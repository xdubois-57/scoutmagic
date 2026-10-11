/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Formulaire d'inscription › Réglages (modules/registration/views/config/
// _settings_capacities.html.twig and _settings_priorities.html.twig),
// issue #843 IT-03:
//
//  - « Gérer les listes d'attente » saves on change (design.md §7.13) and
//    greys the thresholds out or back in on the server's answer — their
//    values are kept either way;
//  - the slider with two handles and the two number fields say the same
//    two thresholds: moving one moves the other, a handle never crosses the
//    other one, and a field keeps what is typed as a draft until it is left
//    or Enter is pressed — then it is rounded and bounded, and an empty or
//    invalid entry gives the last value back;
//  - the worked example says what each level means in places for one
//    capacity;
//  - the « Priorités » box: the three connected lists are the shared list
//    editor's (list-editor.js, sortable.js — no drag engine of its own
//    here); this file adds what is the module's own: « Ordre d'arrivée »
//    refused in « Toujours Oui », the menu that moves a criterion between
//    zones on a small screen, and the field names rewritten from where each
//    row sits when the form is sent.
//
// The pure parts are exposed as window.ScoutMagicRegistrationSettings for
// tests/js/registration-capacity-settings.test.js.
(function () {
    // The thresholds' bounds as the server states them
    // (SlotMath::THRESHOLD_MIN_PERCENT / _MAX_PERCENT); the page's own
    // range inputs carry them, and bindThresholds() reads them from there.
    var DEFAULT_BOUNDS = { min: 1, max: 120 };
    // A handle's diameter, in rem — the same value as the stylesheet's
    // .registration-dual-slider-handle thumb (components.css): a native
    // range input puts value v at half a thumb plus (v − min) / (max − min)
    // of the track left once a thumb is taken off, and the coloured zones
    // follow that, so each handle sits on the boundary it sets.
    var THUMB_REM = 1.5;
    // Written here rather than read from the page: an address taken from
    // the DOM is DOM text, and a request sent to it is the kind of sink
    // CodeQL rightly flags (AGENTS.md § CodeQL).
    var WAITLIST_URL = '/config/inscriptions/listes-attente';

    /**
     * A number field's draft, committed: rounded and bounded, or the last
     * value when the draft is empty or not a number.
     *
     * @param {string} draft
     * @param {number} last
     * @param {number} min
     * @param {number} max
     * @returns {number}
     */
    function commitNumber(draft, last, min, max) {
        var trimmed = String(draft).trim();
        var value = Number(trimmed);
        if (trimmed === '' || !Number.isFinite(value)) {
            return last;
        }
        return Math.min(max, Math.max(min, Math.round(value)));
    }

    /**
     * The pair after one threshold moved: « Oui » stays strictly below
     * « Peut-être », both within the slider's range — the moved one gives
     * way, the other one never moves.
     *
     * @param {'yes'|'maybe'} which
     * @param {number} value
     * @param {{yes: number, maybe: number}} pair
     * @param {{min: number, max: number}} [bounds]
     * @returns {{yes: number, maybe: number}}
     */
    function movePair(which, value, pair, bounds) {
        var range = bounds || DEFAULT_BOUNDS;
        if (which === 'yes') {
            return { yes: Math.max(range.min, Math.min(value, pair.maybe - 1)), maybe: pair.maybe };
        }
        return { yes: pair.yes, maybe: Math.min(range.max, Math.max(value, pair.yes + 1)) };
    }

    /**
     * The three zones and the 100 % mark, as fractions of the track a handle
     * travels: « Oui » up to its handle, « Peut-être » between the two,
     * « Non » after the second, and where 100 % falls.
     *
     * @param {{yes: number, maybe: number}} pair
     * @param {{min: number, max: number}} [bounds]
     * @returns {{yes: number, maybe: number, no: number, full: number}}
     */
    function sliderGeometry(pair, bounds) {
        var range = bounds || DEFAULT_BOUNDS;
        var span = range.max - range.min;
        return {
            yes: (pair.yes - range.min) / span,
            maybe: (pair.maybe - pair.yes) / span,
            no: (range.max - pair.maybe) / span,
            full: (100 - range.min) / span,
        };
    }

    /**
     * A length along the bar: $fraction of the travel, plus half a thumb
     * when the length starts or ends at an edge of the bar — the CSS the
     * template writes on the first render, character for character.
     *
     * @param {number} fraction
     * @param {boolean} withHalfThumb
     * @returns {string}
     */
    function barLength(fraction, withHalfThumb) {
        var rounded = Number(fraction.toFixed(5));
        return 'calc(' + (withHalfThumb ? (THUMB_REM / 2) + 'rem + ' : '') + rounded
            + ' * (100% - ' + THUMB_REM + 'rem))';
    }

    /**
     * The largest number of occupied places still within $percent of
     * $capacity — the bound is included, as on the server
     * (SlotMath::levelForOccupancy()), and the arithmetic is in integers
     * so a bound that falls exactly on a number of places is never missed.
     *
     * @param {number} capacity
     * @param {number} percent
     * @returns {number}
     */
    function maxOccupiedFor(capacity, percent) {
        return Math.floor((percent * capacity) / 100);
    }

    /**
     * « 1 place déjà occupée », « 3 places déjà occupées ».
     *
     * @param {number} count
     * @returns {string}
     */
    function placesOccupied(count) {
        var plural = count > 1 ? 's' : '';
        return count + ' place' + plural + ' déjà occupée' + plural;
    }

    /**
     * The three lines of the worked example, each as a level, the text
     * before the emphasised part, the emphasised part, and the text after.
     *
     * @param {number} capacity
     * @param {{yes: number, maybe: number}} pair
     * @returns {Array<{level: 'yes'|'maybe'|'no', before: string, strong: string, after: string}>}
     */
    function exampleLines(capacity, pair) {
        var yesUpTo = maxOccupiedFor(capacity, pair.yes);
        var maybeUpTo = maxOccupiedFor(capacity, pair.maybe);
        var lines = [];

        lines.push({
            level: 'yes',
            before: "tant qu'il y a ",
            strong: yesUpTo <= 0 ? placesOccupied(0) : 'au plus ' + placesOccupied(yesUpTo),
            after: '',
        });

        if (maybeUpTo <= yesUpTo) {
            lines.push({
                level: 'maybe',
                before: '',
                strong: 'jamais avec cette capacité : les deux limites tombent sur le même nombre de places',
                after: '',
            });
        } else {
            lines.push({
                level: 'maybe',
                before: 'quand il y a ',
                strong: maybeUpTo === yesUpTo + 1
                    ? placesOccupied(maybeUpTo)
                    : 'de ' + (yesUpTo + 1) + ' à ' + maybeUpTo + ' places déjà occupées',
                after: '',
            });
        }

        var free = capacity - maybeUpTo - 1;
        var after;
        if (free > 0) {
            var plural = free > 1 ? 's' : '';
            after = ' (' + free + ' place' + plural + ' libre' + plural + ' ou moins)';
        } else if (free === 0) {
            after = " (l'année est complète)";
        } else {
            after = ' (' + (maybeUpTo + 1 - capacity) + ' de plus que la capacité)';
        }
        lines.push({ level: 'no', before: 'à partir de ', strong: placesOccupied(maybeUpTo + 1), after: after });

        return lines;
    }

    /** @type {Record<string, {label: string, badge: string}>} */
    var LEVELS = {
        yes: { label: 'Oui', badge: 'text-bg-success' },
        maybe: { label: 'Peut-être', badge: 'text-bg-warning' },
        no: { label: 'Non', badge: 'text-bg-danger' },
    };

    /**
     * Writes the worked example into its list — text nodes only, nothing
     * parsed as HTML.
     *
     * @param {HTMLElement} list
     * @param {number} capacity
     * @param {{yes: number, maybe: number}} pair
     */
    function renderExample(list, capacity, pair) {
        list.replaceChildren();
        exampleLines(capacity, pair).forEach(function (line) {
            var item = document.createElement('li');
            item.className = 'mb-1';
            var badge = document.createElement('span');
            badge.className = 'badge ' + LEVELS[line.level].badge + ' me-1';
            badge.textContent = LEVELS[line.level].label;
            var strong = document.createElement('strong');
            strong.textContent = line.strong;
            item.append(badge, line.before, strong, line.after);
            list.appendChild(item);
        });
    }

    /**
     * The thresholds sub-box: slider, fields and example kept in step.
     *
     * @param {HTMLElement} box
     */
    function bindThresholds(box) {
        var yesField = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-threshold-field="yes"]'));
        var maybeField = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-threshold-field="maybe"]'));
        var yesHandle = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-slider-handle="yes"]'));
        var maybeHandle = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-slider-handle="maybe"]'));
        var capacityField = /** @type {HTMLInputElement|null} */ (box.querySelector('[data-example-capacity]'));
        var example = /** @type {HTMLElement|null} */ (box.querySelector('[data-threshold-example]'));
        var overFull = /** @type {HTMLElement|null} */ (box.querySelector('[data-threshold-over-full]'));
        if (!yesField || !maybeField || !yesHandle || !maybeHandle) {
            return;
        }

        var bounds = {
            min: Number(yesHandle.min) || DEFAULT_BOUNDS.min,
            max: Number(yesHandle.max) || DEFAULT_BOUNDS.max,
        };
        // The page always renders a valid pair; these fallbacks only guard
        // against a field emptied before the script ran.
        var pair = {
            yes: commitNumber(yesField.value, bounds.min, bounds.min, bounds.max - 1),
            maybe: commitNumber(maybeField.value, bounds.max, bounds.min + 1, bounds.max),
        };
        var capacity = capacityField ? commitNumber(capacityField.value, 15, 1, 99) : 15;

        function render() {
            yesField.value = String(pair.yes);
            maybeField.value = String(pair.maybe);
            yesHandle.value = String(pair.yes);
            maybeHandle.value = String(pair.maybe);
            if (capacityField) capacityField.value = String(capacity);
            var geometry = sliderGeometry(pair, bounds);
            /** @type {Record<string, string>} */
            var widths = {
                yes: barLength(geometry.yes, true),
                maybe: barLength(geometry.maybe, false),
                no: barLength(geometry.no, true),
            };
            ['yes', 'maybe', 'no'].forEach(function (zone) {
                box.querySelectorAll('[data-slider-zone="' + zone + '"], [data-slider-label="' + zone + '"]').forEach(
                    /** @param {HTMLElement} node */
                    function (node) { node.style.width = widths[zone]; }
                );
            });
            if (overFull) overFull.classList.toggle('d-none', pair.maybe <= 100);
            if (example) renderExample(example, capacity, pair);
        }

        /**
         * @param {'yes'|'maybe'} which
         * @param {number} value
         */
        function move(which, value) {
            pair = movePair(which, value, pair, bounds);
            render();
        }

        yesHandle.addEventListener('input', function () { move('yes', Number(yesHandle.value)); });
        maybeHandle.addEventListener('input', function () { move('maybe', Number(maybeHandle.value)); });

        /**
         * @param {HTMLInputElement} field
         * @param {() => void} commit
         */
        function commitOnLeave(field, commit) {
            field.addEventListener('blur', commit);
            field.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    // Enter commits the draft — the form is sent with the
                    // button, never by a keystroke in a half-typed field.
                    event.preventDefault();
                    commit();
                }
            });
        }

        commitOnLeave(yesField, function () {
            move('yes', commitNumber(yesField.value, pair.yes, bounds.min, pair.maybe - 1));
        });
        commitOnLeave(maybeField, function () {
            move('maybe', commitNumber(maybeField.value, pair.maybe, pair.yes + 1, bounds.max));
        });
        if (capacityField) {
            commitOnLeave(capacityField, function () {
                capacity = commitNumber(capacityField.value, capacity, 1, 99);
                render();
            });
        }

        // A field still holding a draft when the button is pressed: its
        // blur has run by then on a click, but not on a submit by keyboard.
        box.addEventListener('submit', function () {
            pair = movePair('yes', commitNumber(yesField.value, pair.yes, bounds.min, pair.maybe - 1), pair, bounds);
            pair = movePair('maybe', commitNumber(maybeField.value, pair.maybe, pair.yes + 1, bounds.max), pair, bounds);
            render();
        });

        render();
    }

    /**
     * Greys the thresholds out (or back in) and shows the sentences that
     * go with it — what the server rendered after a reload, without one.
     *
     * @param {boolean} enabled
     */
    function applyWaitlistState(enabled) {
        document.querySelectorAll('[data-threshold-box] fieldset').forEach(
            /** @param {HTMLFieldSetElement} fieldset */
            function (fieldset) {
                fieldset.disabled = !enabled;
                fieldset.classList.toggle('opacity-50', !enabled);
            }
        );
        document.querySelectorAll('[data-threshold-disabled-note]').forEach(function (note) {
            note.classList.toggle('d-none', enabled);
        });
        document.querySelectorAll('[data-waitlist-off-mention]').forEach(function (mention) {
            mention.classList.toggle('d-none', enabled);
        });
    }

    /**
     * « Gérer les listes d'attente », saved on change. A refused save puts
     * the switch back the way the server still has it.
     *
     * @param {HTMLInputElement} toggle
     */
    function bindWaitlistSwitch(toggle) {
        toggle.addEventListener('change', function () {
            var wanted = toggle.checked;
            toggle.disabled = true;
            window.ScoutMagicApi.postJson(WAITLIST_URL, { enabled: wanted }).then(function (res) {
                toggle.disabled = false;
                var data = res.data || {};
                if (data.success) {
                    applyWaitlistState(wanted);
                    window.ScoutMagicToast.show(data.message || 'Enregistré.', { variant: 'success' });
                    return;
                }
                toggle.checked = !wanted;
                window.ScoutMagicToast.show(
                    res.status === 0 ? 'Erreur réseau : rien n\'a été enregistré.' : data.error || 'Erreur lors de l\'enregistrement.',
                    { variant: 'error' }
                );
            });
        });
    }

    /**
     * The zone a priority row sits in, read from its list.
     *
     * @param {Element} row
     * @returns {string}
     */
    function zoneOf(row) {
        var list = /** @type {HTMLElement|null} */ (row.closest('.list-editor'));
        return list?.dataset.groupKey || '';
    }

    /**
     * Rewrites every row's hidden field after the list it sits in, so the
     * form sends `priority[<zone>][]` in the order the screen shows.
     *
     * @param {HTMLElement} form
     */
    function syncPriorityFields(form) {
        form.querySelectorAll('.list-editor-item').forEach(function (row) {
            var input = /** @type {HTMLInputElement|null} */ (row.querySelector('[data-priority-input]'));
            var zone = zoneOf(row);
            if (input) input.name = 'priority[' + zone + '][]';
            var menu = /** @type {HTMLSelectElement|null} */ (row.querySelector('[data-priority-move]'));
            if (menu) menu.value = zone;
        });
    }

    /**
     * Whether a priority row may sit in « Toujours Oui ».
     *
     * @param {Element} row
     * @returns {boolean}
     */
    function canBeAlways(row) {
        var input = /** @type {HTMLInputElement|null} */ (row.querySelector('[data-priority-input]'));
        return input?.dataset.canAlways !== '0';
    }

    /**
     * The « Priorités » box.
     *
     * @param {HTMLElement} form
     */
    function bindPriorities(form) {
        var notice = /** @type {HTMLElement|null} */ (form.querySelector('[data-priority-notice]'));
        var message = form.dataset.rankOnlyMessage || '';

        /** @param {string|null} text */
        function say(text) {
            if (!notice) return;
            notice.textContent = text || '';
            notice.classList.toggle('d-none', !text);
        }

        /** @param {string} zone */
        function itemsOf(zone) {
            return /** @type {HTMLElement|null} */ (
                form.querySelector('.list-editor[data-group-key="' + zone + '"] .list-editor-items')
            );
        }

        /** @param {HTMLElement|null} items */
        function refresh(items) {
            if (items && window.ScoutMagicListEditor) window.ScoutMagicListEditor.refresh(items);
        }

        // On a small screen the empty « Désactivés » zone points at the
        // menu, not at a drag nobody can do with a finger.
        var offList = /** @type {HTMLElement|null} */ (form.querySelector('.list-editor[data-group-key="off"]'));
        var touchEmpty = offList?.closest('[data-touch-empty-label]')?.getAttribute('data-touch-empty-label');
        var offEmpty = offList?.querySelector('.list-editor-empty');
        if (offEmpty && touchEmpty && window.matchMedia && !window.matchMedia('(min-width: 992px)').matches) {
            offEmpty.textContent = touchEmpty;
        }

        // Where a dragged row came from, to put it back when it lands
        // where it may not.
        /** @type {{row: HTMLElement, parent: Node|null, next: Node|null}|null} */
        var origin = null;
        form.addEventListener('dragstart', function (event) {
            var row = /** @type {HTMLElement|null} */ (
                /** @type {HTMLElement} */ (event.target).closest?.('.list-editor-item') || null
            );
            origin = row ? { row: row, parent: row.parentNode, next: row.nextSibling } : null;
        }, true);
        form.addEventListener('dragend', function () {
            var moved = origin;
            origin = null;
            // After the shared toolbox has settled the drop.
            window.setTimeout(function () {
                if (!moved) return;
                if (zoneOf(moved.row) === 'always' && !canBeAlways(moved.row)) {
                    var landed = /** @type {HTMLElement|null} */ (moved.row.parentNode);
                    if (moved.parent) moved.parent.insertBefore(moved.row, moved.next);
                    refresh(landed);
                    refresh(/** @type {HTMLElement|null} */ (moved.parent));
                    say(message);
                } else if (zoneOf(moved.row) !== '') {
                    say(null);
                }
                syncPriorityFields(form);
            }, 0);
        });

        // The menu (small screens): moves the row to the end of the zone
        // chosen, or refuses as the drag does.
        form.addEventListener('change', function (event) {
            var menu = /** @type {HTMLSelectElement} */ (event.target);
            if (!menu.matches?.('[data-priority-move]')) return;
            var row = /** @type {HTMLElement|null} */ (menu.closest('.list-editor-item'));
            if (!row) return;
            var from = /** @type {HTMLElement|null} */ (row.parentNode);
            if (menu.value === 'always' && !canBeAlways(row)) {
                menu.value = zoneOf(row);
                say(message);
                return;
            }
            var target = itemsOf(menu.value);
            if (!target || target === from) return;
            var empty = target.querySelector('.list-editor-empty');
            if (empty) {
                target.insertBefore(row, empty);
            } else {
                target.appendChild(row);
            }
            say(null);
            refresh(from);
            refresh(target);
            syncPriorityFields(form);
        });

        form.addEventListener('click', function (event) {
            var target = /** @type {HTMLElement} */ (event.target);
            if (target.closest?.('.list-editor-move-up, .list-editor-move-down')) {
                syncPriorityFields(form);
            }
        });

        form.addEventListener('submit', function () { syncPriorityFields(form); });
        syncPriorityFields(form);
    }

    function init() {
        document.querySelectorAll('[data-threshold-box]').forEach(
            /** @param {HTMLElement} box */
            function (box) { bindThresholds(box); }
        );
        var toggle = /** @type {HTMLInputElement|null} */ (document.querySelector('[data-waitlist-switch]'));
        if (toggle) bindWaitlistSwitch(toggle);
        document.querySelectorAll('[data-priority-form]').forEach(
            /** @param {HTMLElement} form */
            function (form) { bindPriorities(form); }
        );
    }

    window.ScoutMagicRegistrationSettings = {
        commitNumber: commitNumber,
        movePair: movePair,
        sliderGeometry: sliderGeometry,
        barLength: barLength,
        maxOccupiedFor: maxOccupiedFor,
        placesOccupied: placesOccupied,
        exampleLines: exampleLines,
        init: init,
    };

    init();
})();
