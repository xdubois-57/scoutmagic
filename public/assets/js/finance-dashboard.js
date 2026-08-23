/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Finance dashboard (modules/finance/views/dashboard.html.twig): the
// three Chart.js figures, and the PDF fallback on the pending-receipt
// cards. Extracted from the template's two inline <script> blocks so the
// Vitest suite can exercise the production code directly
// (tests/js/finance-dashboard.test.js).
//
// The figures' numbers come from a `<script type="application/json">`
// island rather than an inline assignment to a window.* global (the
// base.html.twig `offline-config-data` precedent): a JSON island is data
// to the parser, not code, so a category named with a « < » cannot end
// the block early, and the template needs no nonce for it.
//
// The fallback for a missing PDF thumbnail is the shared
// ScoutMagicPdfThumbnail — this page held the third copy of it.
(function () {
    var thumbnails = window.ScoutMagicPdfThumbnail;
    if (thumbnails) {
        thumbnails.bind(document, { height: '160px', iconClass: 'fs-1' });
    }

    var blob = document.getElementById('finance-dashboard-data');
    var Chart = window.Chart;
    if (!blob || !Chart) {
        return;
    }

    /** @type {any} */
    var data;
    try {
        data = JSON.parse(blob.textContent || '{}');
    } catch (e) {
        return;
    }

    // Greens and blues for what a category brought in, reds and oranges
    // for what it cost. The last colour of each ramp repeats: beyond five
    // categories the pie's own labels carry the identification, and
    // recycling the first colours would pair a sixth category with the
    // largest slice's colour.
    var POSITIVE_COLORS = ['#198754', '#20c997', '#0d6efd', '#0dcaf0', '#adb5bd'];
    var NEGATIVE_COLORS = ['#dc3545', '#fd7e14', '#6f42c1', '#d63384', '#adb5bd'];

    /**
     * @param {Array<any>} rows
     * @param {Array<string>} ramp
     * @returns {Array<string>}
     */
    function colorsFor(rows, ramp) {
        return rows.map(function (row, i) {
            return ramp[i] || ramp[ramp.length - 1];
        });
    }

    /**
     * @param {string} id
     * @param {any} config
     */
    function draw(id, config) {
        var canvas = /** @type {HTMLCanvasElement|null} */ (document.getElementById(id));
        if (!canvas) {
            return;
        }
        new Chart(canvas, config);
    }

    var positive = data.netPositive || [];
    var negative = data.netNegative || [];

    draw('chart-net-category-pie', {
        type: 'pie',
        data: {
            labels: positive.map(function (r) { return r.label; })
                .concat(negative.map(function (r) { return r.label; })),
            datasets: [{
                data: positive.map(function (r) { return r.net; })
                    .concat(negative.map(function (r) { return r.net; })),
                backgroundColor: colorsFor(positive, POSITIVE_COLORS)
                    .concat(colorsFor(negative, NEGATIVE_COLORS))
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    draw('chart-income-expense-bar', {
        type: 'bar',
        data: {
            labels: data.categoryLabels || [],
            datasets: [
                { label: 'Recettes', data: data.categoryIncome || [], backgroundColor: '#198754' },
                { label: 'Dépenses', data: data.categoryExpense || [], backgroundColor: '#dc3545' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true } }
        }
    });

    draw('chart-balance-line', {
        type: 'line',
        data: {
            labels: data.evolutionLabels || [],
            datasets: [{
                label: 'Solde',
                data: data.evolutionBalances || [],
                borderColor: '#0d6efd',
                tension: 0.2
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });
})();
