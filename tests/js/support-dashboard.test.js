// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch and Chart.js are mocked throughout. Exercises
// the REAL implementation in public/assets/js/support-dashboard.js
// (imported below, never reimplemented here).
//
// The property this file exists to protect is stated in the script's own
// header and in ARCHITECTURE.md §8.50: **nothing on the support dashboard
// assembles markup from a remote installation's values client-side.** The
// chart series and the detail dialog both arrive already rendered or
// already escaped from the server, and a future "small convenience" that
// built a label with string concatenation here would silently undo that.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The script registers a delegated click listener on `document`, which
// jsdom keeps alive between tests in the same file even though the body is
// rebuilt — so a scenario's boot() would otherwise still be listening
// during the next one. Every registration is recorded and torn down.
const documentListeners = [];

function el(html) {
    const div = document.createElement('div');
    div.innerHTML = html.trim();
    return div.firstElementChild;
}

function buildDom() {
    document.body.innerHTML = '';
    [
        el('<div><canvas id="chart-versions"></canvas></div>'),
        el('<div><canvas id="chart-auto-update"></canvas></div>'),
        el('<div><canvas id="chart-history"></canvas></div>'),
        el('<table><tbody><tr><td><button type="button" class="support-detail-btn" data-installation-id="7">Voir</button></td></tr></tbody></table>'),
        el('<div class="modal" id="support-detail-modal"><div class="modal-body" id="support-detail-body"></div></div>'),
    ].forEach((node) => document.body.appendChild(node));
}

/** A Chart.js double recording every configuration it is handed. */
function mockChart() {
    const built = [];
    const ChartDouble = vi.fn(function (canvas, config) {
        built.push({ canvas, config });
    });
    global.window.Chart = ChartDouble;

    return built;
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/support-dashboard.js');
}

beforeEach(() => {
    vi.restoreAllMocks();
    buildDom();
    delete global.window.Chart;
    delete global.window.bootstrap;
    delete global.window.supportDashboardCharts;
    global.fetch = vi.fn(() => Promise.resolve({ ok: true, text: () => Promise.resolve('<p>détail</p>') }));

    const register = document.addEventListener.bind(document);
    vi.spyOn(document, 'addEventListener').mockImplementation((type, handler, options) => {
        documentListeners.push([type, handler]);
        register(type, handler, options);
    });
});

afterEach(() => {
    documentListeners.splice(0).forEach(([type, handler]) => document.removeEventListener(type, handler));
});

describe('support-dashboard.js: charts', () => {
    it('builds nothing at all when the page carries no series', async () => {
        const built = mockChart();

        await boot();

        expect(built).toHaveLength(0);
    });

    it('builds one doughnut per current-state chart and one line for the history', async () => {
        const built = mockChart();
        global.window.supportDashboardCharts = {
            versions: [{ label: '1.0.33', count: 3 }, { label: '1.0.33 (dev)', count: 1 }],
            autoUpdate: [{ label: 'Désactivées', count: 2 }],
            history: [{ month: '2026-06', count: 4 }, { month: '2026-07', count: 6 }],
        };

        await boot();

        expect(built.map((b) => b.config.type)).toEqual(['doughnut', 'doughnut', 'line']);
    });

    it('passes labels and counts straight through, never rebuilt as markup', async () => {
        const built = mockChart();
        global.window.supportDashboardCharts = {
            versions: [{ label: '1.0.33 (dev)', count: 3 }],
            autoUpdate: [],
            history: [],
        };

        await boot();

        expect(built[0].config.data.labels).toEqual(['1.0.33 (dev)']);
        expect(built[0].config.data.datasets[0].data).toEqual([3]);
    });

    it('gives a colour to every slice, cycling the palette past its length', async () => {
        const built = mockChart();
        global.window.supportDashboardCharts = {
            versions: Array.from({ length: 13 }, (unused, i) => ({ label: 'v' + i, count: 1 })),
            autoUpdate: [],
            history: [],
        };

        await boot();

        const colours = built[0].config.data.datasets[0].backgroundColor;
        expect(colours).toHaveLength(13);
        expect(colours[10]).toBe(colours[0]);
        expect(colours.every((c) => /^#[0-9a-f]{6}$/i.test(c))).toBe(true);
    });

    it('skips an empty series rather than drawing an empty chart', async () => {
        const built = mockChart();
        global.window.supportDashboardCharts = {
            versions: [{ label: '1.0.33', count: 1 }],
            autoUpdate: [],
            history: [],
        };

        await boot();

        expect(built).toHaveLength(1);
    });

    it('does not throw when Chart.js failed to load', async () => {
        global.window.supportDashboardCharts = {
            versions: [{ label: '1.0.33', count: 1 }],
            autoUpdate: [],
            history: [],
        };

        await expect(boot()).resolves.toBeUndefined();
    });

    it('starts the history axis at zero, in whole installations', async () => {
        const built = mockChart();
        global.window.supportDashboardCharts = {
            versions: [],
            autoUpdate: [],
            history: [{ month: '2026-07', count: 6 }],
        };

        await boot();

        const scales = built[0].config.options.scales;
        expect(scales.y.beginAtZero).toBe(true);
        expect(scales.y.ticks.precision).toBe(0);
    });
});

describe('support-dashboard.js: the detail dialog', () => {
    function clickDetail() {
        document.querySelector('.support-detail-btn').click();
    }

    it('fetches the server-rendered body for the clicked installation', async () => {
        await boot();

        clickDetail();
        await Promise.resolve();

        expect(fetch).toHaveBeenCalledWith('/support-dashboard/installations/7');
    });

    it('encodes the id rather than concatenating it into the path', async () => {
        document.querySelector('.support-detail-btn').setAttribute('data-installation-id', '7/../secret');
        await boot();

        clickDetail();
        await Promise.resolve();

        expect(fetch).toHaveBeenCalledWith('/support-dashboard/installations/7%2F..%2Fsecret');
    });

    it('injects only what the server rendered — never anything assembled here', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            text: () => Promise.resolve('<p id="from-twig">rendu côté serveur</p>'),
        }));
        await boot();

        clickDetail();
        await vi.waitFor(() => {
            expect(document.getElementById('from-twig')).not.toBeNull();
        });
    });

    it('reports a failed fetch as text, never as markup', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 404, text: () => Promise.resolve('') }));
        await boot();

        clickDetail();
        await vi.waitFor(() => {
            expect(document.getElementById('support-detail-body').textContent)
                .toContain('Impossible de charger');
        });
        expect(document.getElementById('support-detail-body').children).toHaveLength(0);
    });

    it('reports a network error the same way', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        await boot();

        clickDetail();
        await vi.waitFor(() => {
            expect(document.getElementById('support-detail-body').textContent)
                .toContain('Impossible de charger');
        });
    });

    it('opens the Bootstrap modal when Bootstrap is present', async () => {
        const show = vi.fn();
        global.window.bootstrap = { Modal: { getOrCreateInstance: vi.fn(() => ({ show })) } };
        await boot();

        clickDetail();

        expect(show).toHaveBeenCalled();
    });

    it('still fetches when Bootstrap is absent, rather than throwing', async () => {
        await boot();

        clickDetail();
        await Promise.resolve();

        expect(fetch).toHaveBeenCalled();
    });

    it('ignores a click anywhere that is not a detail button', async () => {
        await boot();

        document.querySelector('#support-detail-modal').click();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('ignores a detail button carrying no installation id', async () => {
        document.querySelector('.support-detail-btn').removeAttribute('data-installation-id');
        await boot();

        clickDetail();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('registers no delegated listener at all when the dialog is absent', async () => {
        document.getElementById('support-detail-modal').remove();

        await boot();

        expect(documentListeners).toHaveLength(0);
    });
});
