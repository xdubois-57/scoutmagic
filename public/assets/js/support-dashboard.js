// Modules\SupportDashboard — the two current-state charts and the detail
// dialog on /support-dashboard.
//
// Everything shown here was computed server-side: the chart series arrive as
// window.supportDashboardCharts (set by an inline, nonce-tagged script in the
// page), and the dialog body is HTML rendered by Twig and fetched on click.
// Nothing on this page builds markup from a remote installation's own values
// client-side — those values are untrusted text, and Twig's autoescaping is a
// far better guarantee than an escaper written here (SECURITY.md § 28).
(function () {
    var palette = [
        '#0d6efd', '#198754', '#fd7e14', '#6f42c1', '#d63384',
        '#0dcaf0', '#ffc107', '#20c997', '#dc3545', '#adb5bd'
    ];

    /**
     * @param {Array<{label: string, count: number}>} series
     * @param {string} canvasId
     * @returns {void}
     */
    function renderChart(series, canvasId) {
        var canvas = /** @type {HTMLCanvasElement|null} */ (document.getElementById(canvasId));
        if (!canvas || typeof window.Chart !== 'function' || !series || series.length === 0) {
            return;
        }

        new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: series.map(function (entry) { return entry.label; }),
                datasets: [{
                    data: series.map(function (entry) { return entry.count; }),
                    backgroundColor: series.map(function (entry, index) {
                        return palette[index % palette.length];
                    })
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    /**
     * The monthly history: one line, one point per finalised month. A line
     * rather than a doughnut because the axis is time, and the whole point
     * of the series is the shape it makes across it.
     *
     * @param {Array<{month: string, count: number}>} series
     * @returns {void}
     */
    function renderHistoryChart(series) {
        var canvas = /** @type {HTMLCanvasElement|null} */ (document.getElementById('chart-history'));
        if (!canvas || typeof window.Chart !== 'function' || !series || series.length === 0) {
            return;
        }

        new window.Chart(canvas, {
            type: 'line',
            data: {
                labels: series.map(function (entry) { return entry.month; }),
                datasets: [{
                    label: 'Installations ayant émis',
                    data: series.map(function (entry) { return entry.count; }),
                    borderColor: palette[0],
                    backgroundColor: palette[0],
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                // A count of installations is a whole number, and starting
                // the axis anywhere but zero would exaggerate every wobble.
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    var charts = window.supportDashboardCharts;
    if (charts) {
        renderChart(charts.versions, 'chart-versions');
        renderChart(charts.autoUpdate, 'chart-auto-update');
        renderHistoryChart(charts.history);
    }

    var modalElement = document.getElementById('support-detail-modal');
    var modalBody = document.getElementById('support-detail-body');
    if (!modalElement || !modalBody) {
        return;
    }

    document.addEventListener('click', function (event) {
        var target = /** @type {HTMLElement|null} */ (event.target);
        var button = target ? target.closest('.support-detail-btn') : null;
        if (!button) {
            return;
        }

        var installationId = button.getAttribute('data-installation-id');
        if (!installationId) {
            return;
        }

        modalBody.textContent = 'Chargement…';

        var Modal = window.bootstrap && window.bootstrap.Modal;
        if (Modal) {
            Modal.getOrCreateInstance(modalElement).show();
        }

        fetch('/support-dashboard/installations/' + encodeURIComponent(installationId))
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('not found');
                }
                return response.text();
            })
            .then(function (html) {
                // Server-rendered, Twig-escaped markup — never assembled here.
                modalBody.innerHTML = html;
            })
            .catch(function () {
                modalBody.textContent = 'Impossible de charger le détail de cette installation.';
            });
    });
})();
