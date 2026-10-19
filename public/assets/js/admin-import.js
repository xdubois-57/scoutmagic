/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The Desk import page (core/View/templates/admin/import.html.twig).
//
// Two jobs, both conveniences around a decision the server takes:
//
//  - when the chosen year asks for a confirmation — the public year while
//    the next one is already importable, or a year configuration mode
//    alone opens — the submit asks first through ScoutMagicConfirm, and
//    only then sends `confirmed_year` with the year's id. The server
//    (Core\Import\DeskImportYearPolicy) demands that field for exactly
//    those years and refuses the import without it, so a POST that never
//    loaded this script, or one replayed from the console, gets nothing.
//    Cancelling leaves the form as it was, file included.
//  - the « Dernier import » block follows the selected year, redrawn from
//    the page's JSON island without a request.
//
// The pure parts are exported for tests/js; the rest is DOM glue.
(function () {
    'use strict';

    var ISLAND_ID = 'desk-import-years';

    /**
     * @typedef {{title: string, message: string, confirmLabel: string}} YearConfirmation
     * @typedef {{id: number, summary: string}} YearLastImport
     * @typedef {{id: number, label: string, confirm: (YearConfirmation|null),
     *            lastImport: (YearLastImport|null)}} ImportYear
     */

    /**
     * The island's entry for one year id, or null.
     *
     * @param {{years?: ImportYear[]}|null} data
     * @param {string|number} yearId
     * @returns {ImportYear|null}
     */
    function yearFor(data, yearId) {
        var years = data && Array.isArray(data.years) ? data.years : [];
        var id = Number(yearId);
        for (var i = 0; i < years.length; i++) {
            if (years[i] && years[i].id === id) {
                return years[i];
            }
        }
        return null;
    }

    /**
     * The question to ask before importing into $yearId, or null when the
     * year needs none.
     *
     * @param {{years?: ImportYear[]}|null} data
     * @param {string|number} yearId
     * @returns {YearConfirmation|null}
     */
    function confirmationFor(data, yearId) {
        var year = yearFor(data, yearId);
        return year && year.confirm ? year.confirm : null;
    }

    /**
     * Whether the form may go as it is: no question for this year, or the
     * question already answered for this very year.
     *
     * @param {{years?: ImportYear[]}|null} data
     * @param {string} yearId
     * @param {string} confirmedYear
     * @returns {boolean}
     */
    function maySubmit(data, yearId, confirmedYear) {
        return confirmationFor(data, yearId) === null || (confirmedYear !== '' && confirmedYear === yearId);
    }

    /**
     * Whole positive numbers only: these become part of a URL.
     *
     * @param {unknown} value
     * @returns {boolean}
     */
    function isId(value) {
        return typeof value === 'number' && Number.isInteger(value) && value > 0;
    }

    /**
     * Redraws the « Dernier import » block for one year: its heading, the
     * summary sentence (built server-side) and the two links, or the empty
     * state. Text only — nothing from the island is ever parsed as HTML.
     *
     * @param {HTMLElement} container
     * @param {ImportYear|null} year
     */
    function renderLastImport(container, year) {
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
        if (!year || !isId(year.id)) {
            return;
        }

        var heading = document.createElement('h2');
        heading.className = 'h5';
        heading.textContent = 'Dernier import de ' + year.label;
        container.appendChild(heading);

        var last = year.lastImport;
        if (!last || !isId(last.id)) {
            var empty = document.createElement('p');
            empty.className = 'text-body-secondary fst-italic mb-0';
            empty.textContent = 'Aucun import pour ' + year.label + '.';
            container.appendChild(empty);
            return;
        }

        var summary = document.createElement('p');
        summary.className = 'small text-body-secondary mb-2';
        summary.textContent = last.summary;
        container.appendChild(summary);

        var report = document.createElement('a');
        report.className = 'btn btn-outline-secondary btn-sm';
        report.href = '/admin/import/' + String(last.id) + '/rapport';
        report.textContent = 'Voir son rapport';
        container.appendChild(report);
        container.appendChild(document.createTextNode(' '));

        var history = document.createElement('a');
        history.className = 'btn btn-outline-secondary btn-sm';
        history.href = '/admin/import/historique?annee=' + String(year.id);
        history.textContent = 'Historique de ' + year.label;
        container.appendChild(history);
    }

    window.ScoutMagicDeskImport = {
        confirmationFor: confirmationFor,
        maySubmit: maySubmit,
        renderLastImport: renderLastImport
    };

    document.addEventListener('DOMContentLoaded', function () {
        var form = /** @type {HTMLFormElement|null} */ (document.getElementById('desk-import-form'));
        var select = /** @type {HTMLSelectElement|null} */ (document.getElementById('scout-year-id'));
        var confirmed = /** @type {HTMLInputElement|null} */ (document.getElementById('desk-import-confirmed-year'));
        if (!form || !select || !confirmed) {
            return;
        }

        var data = window.ScoutMagicApi ? window.ScoutMagicApi.pageData(ISLAND_ID) : null;
        var last = document.getElementById('desk-import-last');
        var asking = false;

        select.addEventListener('change', function () {
            // A confirmation is for one year: picking another withdraws it.
            confirmed.value = '';
            if (last) {
                renderLastImport(last, yearFor(data, select.value));
            }
        });

        form.addEventListener('submit', async function (event) {
            var yearId = select.value;
            if (maySubmit(data, yearId, confirmed.value)) {
                return;
            }

            event.preventDefault();
            if (asking) {
                return;
            }

            var question = confirmationFor(data, yearId);
            asking = true;
            var ok = false;
            try {
                ok = await window.ScoutMagicConfirm.ask({
                    title: question.title,
                    message: question.message,
                    confirmLabel: question.confirmLabel,
                    // Nothing is destroyed: the import replaces the year
                    // with what Desk holds, and can be run again.
                    variant: 'primary'
                });
            } finally {
                asking = false;
            }
            if (!ok || select.value !== yearId) {
                return;
            }

            confirmed.value = yearId;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });

        // Until this line runs, « Importer » submits natively, unasked, and
        // the server refuses a year that needs confirming. A test (or a
        // future script) waits for this mark rather than for the page to
        // look ready.
        form.dataset.yearConfirmReady = '1';
    });
})();
