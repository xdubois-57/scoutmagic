/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * « Reprendre les dates d'une activité » on the parental authorization
 * screen (modules/official_documents/views/parental_authorization.html.twig).
 *
 * It fills the two date fields and does nothing else. **No event identifier
 * ever reaches the server**: the picker is a convenience, so its value is
 * the two dates themselves, carried in the option as `start|end`, and the
 * form posts the date fields exactly as if they had been typed. There is
 * therefore nothing server-side to re-resolve and no id to trust — the
 * `<select>` itself has no `name` at all, so it is not even submitted.
 *
 * Absent from the page when the calendar module is disabled; this script
 * then finds no picker and returns, and the two date fields work unchanged.
 */
(function () {
    'use strict';

    /**
     * Split an option's value into the two dates it carries.
     *
     * Answers null for anything that is not exactly « start|end » with both
     * halves ISO dates — the empty « Choisir une activité… » option, and
     * anything a page edit might leave behind. The alternative is writing
     * garbage into a date field that then silently posts empty, which is
     * the one failure a parent would not understand.
     *
     * @param {string} value
     * @returns {{start: string, end: string}|null}
     */
    function datesFrom(value) {
        var halves = String(value || '').split('|');
        if (halves.length !== 2) {
            return null;
        }

        var isoDate = /^\d{4}-\d{2}-\d{2}$/;
        if (!isoDate.test(halves[0]) || !isoDate.test(halves[1])) {
            return null;
        }

        return { start: halves[0], end: halves[1] };
    }

    /**
     * @param {HTMLSelectElement} picker
     * @param {HTMLInputElement} startField
     * @param {HTMLInputElement} endField
     */
    function wire(picker, startField, endField) {
        picker.addEventListener('change', function () {
            var dates = datesFrom(picker.value);
            if (dates === null) {
                // Back to « Choisir une activité… ». What the parent may
                // have typed by hand stays: clearing it here would undo a
                // deliberate entry on a mis-click.
                return;
            }

            startField.value = dates.start;
            endField.value = dates.end;
        });
    }

    var picker = /** @type {HTMLSelectElement|null} */ (document.querySelector('[data-event-picker]'));
    var startField = /** @type {HTMLInputElement|null} */ (document.getElementById('start-date'));
    var endField = /** @type {HTMLInputElement|null} */ (document.getElementById('end-date'));

    if (picker && startField && endField) {
        wire(picker, startField, endField);
    }

    // Exposed for tests/js/official-documents-event-picker.test.js, which
    // exercises the real parsing rather than a copy of it.
    window.ScoutMagicEventPicker = { datesFrom: datesFrom };
})();
