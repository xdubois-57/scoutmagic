// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network. Chart.js is stubbed: what this
// suite owns is which figures the page asks for and with what numbers
// and colours, not how Chart.js paints a pie. Exercises the REAL
// implementation in public/assets/js/finance-dashboard.js (imported
// below, never reimplemented here). That file is an IIFE that reads the
// DOM at import time, so each test builds its fixture first and then
// imports the module via vi.resetModules() + await import().
//
// The fixture mirrors what modules/finance/views/dashboard.html.twig
// renders: the three canvases, the JSON island the template serializes
// the figures into, and a pending-receipt card whose PDF thumbnail can
// fail.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const CANVASES = `
    <canvas id="chart-net-category-pie"></canvas>
    <canvas id="chart-income-expense-bar"></canvas>
    <canvas id="chart-balance-line"></canvas>`;

const RECEIPT_CARD =
    '<img class="pdf-thumbnail" src="/files/9/thumbnail" alt="reçu.pdf">';

const DATA = {
    categoryLabels: ['Cotisations', 'Non catégorisé'],
    categoryIncome: [1200, 40],
    categoryExpense: [0, 310],
    evolutionLabels: ['2026-01', '2026-02'],
    evolutionBalances: [1200, 930],
    netPositive: [{ label: 'Cotisations', net: 1200 }],
    netNegative: [{ label: 'Non catégorisé', net: -270 }],
};

function island(data) {
    return `<script type="application/json" id="finance-dashboard-data">${JSON.stringify(data)}<\/script>`;
}

describe('finance-dashboard.js', () => {
    let Chart;

    beforeEach(() => {
        vi.resetModules();
        document.body.innerHTML = `${RECEIPT_CARD}${CANVASES}${island(DATA)}`;
        Chart = vi.fn();
        window.Chart = Chart;
    });

    async function boot() {
        await import('../../public/assets/js/pdf-thumbnail.js');
        await import('../../public/assets/js/finance-dashboard.js');
    }

    /** The config the page asked Chart.js for, by canvas id. */
    function chartOn(id) {
        const call = Chart.mock.calls.find(([canvas]) => canvas && canvas.id === id);
        return call ? call[1] : null;
    }

    describe('entry guard', () => {
        it('draws nothing on a page with no data island (no fiscal year yet)', async () => {
            document.body.innerHTML = CANVASES;
            await boot();

            expect(Chart).not.toHaveBeenCalled();
        });

        it('draws nothing when Chart.js was not loaded', async () => {
            window.Chart = undefined;
            await expect(boot()).resolves.not.toThrow();
        });

        it('survives an unparseable island rather than taking the page down', async () => {
            document.body.innerHTML = CANVASES
                + '<script type="application/json" id="finance-dashboard-data">{oops<\/script>';
            await expect(boot()).resolves.not.toThrow();
            expect(Chart).not.toHaveBeenCalled();
        });

        it('skips a figure whose canvas the template did not render', async () => {
            document.body.innerHTML = '<canvas id="chart-balance-line"></canvas>' + island(DATA);
            await boot();

            expect(Chart).toHaveBeenCalledTimes(1);
            expect(chartOn('chart-balance-line')).not.toBeNull();
        });
    });

    describe('the three figures', () => {
        it('draws all three, each on its own canvas', async () => {
            await boot();

            expect(Chart).toHaveBeenCalledTimes(3);
            expect(chartOn('chart-net-category-pie').type).toBe('pie');
            expect(chartOn('chart-income-expense-bar').type).toBe('bar');
            expect(chartOn('chart-balance-line').type).toBe('line');
        });

        it('puts what came IN and what went OUT in the same pie, in that order', async () => {
            await boot();
            const pie = chartOn('chart-net-category-pie').data;

            expect(pie.labels).toEqual(['Cotisations', 'Non catégorisé']);
            expect(pie.datasets[0].data).toEqual([1200, -270]);
        });

        it('colours income green-to-blue and expense red-to-orange', async () => {
            await boot();

            expect(chartOn('chart-net-category-pie').data.datasets[0].backgroundColor)
                .toEqual(['#198754', '#dc3545']);
        });

        it('repeats the last colour of a ramp past its fifth category', async () => {
            document.body.innerHTML = CANVASES + island({
                ...DATA,
                netPositive: [1, 2, 3, 4, 5, 6, 7].map((n) => ({ label: 'C' + n, net: n })),
                netNegative: [],
            });
            await boot();

            const colors = chartOn('chart-net-category-pie').data.datasets[0].backgroundColor;
            expect(colors.slice(0, 5)).toEqual(['#198754', '#20c997', '#0d6efd', '#0dcaf0', '#adb5bd']);
            expect(colors.slice(5)).toEqual(['#adb5bd', '#adb5bd']);
        });

        it('gives the bar chart two named series against the category labels', async () => {
            await boot();
            const bar = chartOn('chart-income-expense-bar').data;

            expect(bar.labels).toEqual(['Cotisations', 'Non catégorisé']);
            expect(bar.datasets.map((d) => d.label)).toEqual(['Recettes', 'Dépenses']);
            expect(bar.datasets[0].data).toEqual([1200, 40]);
            expect(bar.datasets[1].data).toEqual([0, 310]);
        });

        it('starts the bar chart\'s axis at zero — a truncated axis exaggerates a gap', async () => {
            await boot();

            expect(chartOn('chart-income-expense-bar').options.scales.y.beginAtZero).toBe(true);
        });

        it('plots the balance month by month', async () => {
            await boot();
            const line = chartOn('chart-balance-line').data;

            expect(line.labels).toEqual(['2026-01', '2026-02']);
            expect(line.datasets[0]).toMatchObject({ label: 'Solde', data: [1200, 930] });
        });

        it('lets each figure fill its own box rather than keep a fixed ratio', async () => {
            await boot();

            ['chart-net-category-pie', 'chart-income-expense-bar', 'chart-balance-line']
                .forEach((id) => {
                    expect(chartOn(id).options.responsive).toBe(true);
                    expect(chartOn(id).options.maintainAspectRatio).toBe(false);
                });
        });

        it('draws empty figures rather than throwing when a series is missing', async () => {
            document.body.innerHTML = CANVASES + island({});
            await boot();

            expect(Chart).toHaveBeenCalledTimes(3);
            expect(chartOn('chart-balance-line').data.labels).toEqual([]);
            expect(chartOn('chart-net-category-pie').data.labels).toEqual([]);
        });
    });

    describe('pending receipts', () => {
        it('swaps a failed PDF thumbnail for the shared file icon', async () => {
            await boot();
            document.querySelector('img.pdf-thumbnail').dispatchEvent(new Event('error'));

            expect(document.querySelector('img.pdf-thumbnail')).toBeNull();
            expect(document.querySelector('i.bi-file-earmark-pdf')).not.toBeNull();
        });

        it('wires the thumbnails even on a page with no figures at all', async () => {
            document.body.innerHTML = RECEIPT_CARD;
            await boot();
            document.querySelector('img.pdf-thumbnail').dispatchEvent(new Event('error'));

            expect(document.querySelector('img.pdf-thumbnail')).toBeNull();
        });
    });
});
