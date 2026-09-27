// Core\Mail\Feedback — the two week-by-week charts of « Courrier sortant »
// (issue #420): the DMARC authentication rate, and where the seed copies landed
// per provider.
//
// Everything drawn here was computed server-side and arrives as a JSON island
// (`dmarc-trend-data`, `seed-trend-data`) read through ScoutMagicApi.pageData().
// Nothing on this page builds markup from those values: the labels go into
// Chart.js as data, never into innerHTML, and the figures a reader may need in
// text are rendered by Twig in the <details> table beside each chart
// (SECURITY.md § 28).
//
// **A null value is a hole and must stay one.** `spanGaps: false` is what makes
// the line break where the week carried too little evidence to say anything —
// the whole arbitration of #420 is that a hole and a zero are different claims,
// and joining across one would draw a slope nobody measured.
(function () {
    // The same order as Modules\SupportDashboard's palette, so two charts of
    // this site never disagree about which colour a first series is.
    var palette = [
        '#0d6efd', '#198754', '#fd7e14', '#6f42c1', '#d63384',
        '#0dcaf0', '#ffc107', '#20c997', '#dc3545', '#adb5bd'
    ];

    /**
     * Shared axis and tooltip setup: a percentage from 0 to 100, and a tooltip
     * that says how much evidence the week carried. Both charts answer « quelle
     * part » and neither is comparable to a count, so the scale is fixed rather
     * than fitted to the data — an axis that rescaled itself would make a
     * one-point dip look like a collapse.
     *
     * **`seriesList` is indexed by DATASET, not a single series.** One chart
     * here carries one line per provider, and a tooltip has to answer for the
     * line being hovered: closing over the first provider's points made every
     * provider's tooltip report the first one's `sample` — the one figure the
     * threshold is judged against, wrong for every line but the first.
     *
     * @param {Array<Array<{label: string, sample: number, partial: boolean,
     *     truncated: boolean}>>} seriesList one entry per dataset, same order
     * @param {string} sampleNoun
     * @returns {object}
     */
    function options(seriesList, sampleNoun) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    min: 0,
                    max: 100,
                    ticks: { callback: function (value) { return value + ' %'; } }
                },
                x: { title: { display: true, text: 'Semaine du' } }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        afterBody: function (items) {
                            var series = seriesList[items[0].datasetIndex] || [];
                            var point = series[items[0].dataIndex];
                            if (!point) {
                                return '';
                            }
                            // Two different kinds of short week, and the
                            // tooltip may not confuse them: the last point
                            // will still grow, while the first one never
                            // will — what is missing from it was purged. A
                            // window inside one week is both.
                            var notes = [];
                            if (point.partial) {
                                notes.push('semaine en cours');
                            }
                            if (point.truncated) {
                                notes.push('semaine entamée : les jours précédents ont été purgés');
                            }
                            var suffix = notes.length > 0 ? ' — ' + notes.join(', ') : '';

                            return point.sample + ' ' + sampleNoun + suffix;
                        }
                    }
                }
            }
        };
    }

    /**
     * @param {Array<{label: string, value: number|null}>} points
     * @returns {Array<number|null>} percentages, nulls preserved as holes
     */
    function percentages(points) {
        return points.map(function (point) {
            return point.value === null ? null : Math.round(point.value * 1000) / 10;
        });
    }

    /**
     * @param {string} canvasId
     * @param {object} config
     * @returns {void}
     */
    function draw(canvasId, config) {
        var canvas = /** @type {HTMLCanvasElement|null} */ (document.getElementById(canvasId));
        // No canvas on this page, or no Chart.js: the <details> table beside it
        // carries the same weeks in text, so there is nothing to fall back to
        // and nothing to apologise for.
        if (!canvas || typeof window.Chart !== 'function') {
            return;
        }

        new window.Chart(canvas, config);
    }

    var api = window.ScoutMagicApi;
    if (!api || typeof api.pageData !== 'function') {
        return;
    }

    var dmarc = api.pageData('dmarc-trend-data');
    if (dmarc && dmarc.length > 0) {
        draw('dmarc-trend-chart', {
            type: 'line',
            data: {
                labels: dmarc.map(function (point) { return point.label; }),
                datasets: [{
                    label: 'Authentifiés',
                    data: percentages(dmarc),
                    borderColor: palette[0],
                    backgroundColor: palette[0],
                    spanGaps: false,
                    tension: 0
                }]
            },
            options: options([dmarc], 'messages rapportés')
        });
    }

    var seeds = api.pageData('seed-trend-data');
    if (seeds) {
        var providers = Object.keys(seeds);
        if (providers.length > 0) {
            // Every provider is drawn against the same weeks, which is what
            // makes the comparison readable: the labels come from the first
            // series because the server built them all from one window.
            draw('seed-trend-chart', {
                type: 'line',
                data: {
                    labels: seeds[providers[0]].map(function (point) { return point.label; }),
                    datasets: providers.map(function (provider, index) {
                        return {
                            label: provider,
                            data: percentages(seeds[provider]),
                            borderColor: palette[index % palette.length],
                            backgroundColor: palette[index % palette.length],
                            spanGaps: false,
                            tension: 0
                        };
                    })
                },
                options: options(
                    providers.map(function (provider) { return seeds[provider]; }),
                    'publipostages mesurés'
                )
            });
        }
    }
})();
