// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no real network. Chart.js is stubbed: what this suite owns is
// which figures the two « Courrier sortant » charts ask for, and above all
// that a week nobody measured stays a HOLE all the way into the dataset —
// not how Chart.js paints a line. Exercises the REAL implementation in
// public/assets/js/outbound-mail-trends.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the module
// via vi.resetModules() + await import().
//
// The fixture mirrors what core/View/templates/config/outbound_mail/
// dmarc.html.twig and seeds.html.twig render: a canvas and the JSON island
// the controller serialized the weeks into.
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** Three weeks: one measured, one nobody measured, one still filling up. */
const DMARC = [
    { label: '7 septembre 2026', value: 0.9231, sample: 130, partial: false },
    { label: '14 septembre 2026', value: null, sample: 4, partial: false },
    { label: '21 septembre 2026', value: 1, sample: 60, partial: true },
];

const SEEDS = {
    'gmail.com': [
        { label: '7 septembre 2026', value: 1, sample: 6, partial: false },
        { label: '14 septembre 2026', value: 0.5, sample: 8, partial: false },
    ],
    'outlook.com': [
        { label: '7 septembre 2026', value: null, sample: 2, partial: false },
        { label: '14 septembre 2026', value: 0.25, sample: 5, partial: false },
    ],
};

function island(id, data) {
    return `<script type="application/json" id="${id}">${JSON.stringify(data)}<\/script>`;
}

describe('outbound-mail-trends.js', () => {
    let Chart;

    beforeEach(() => {
        vi.resetModules();
        Chart = vi.fn();
        window.Chart = Chart;
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/outbound-mail-trends.js');
    }

    function dmarcPage(points = DMARC) {
        document.body.innerHTML =
            '<canvas id="dmarc-trend-chart"></canvas>' + island('dmarc-trend-data', points);
    }

    function seedPage(series = SEEDS) {
        document.body.innerHTML =
            '<canvas id="seed-trend-chart"></canvas>' + island('seed-trend-data', series);
    }

    /** The config the page asked Chart.js for, by canvas id. */
    function chartOn(id) {
        const call = Chart.mock.calls.find(([canvas]) => canvas && canvas.id === id);
        return call ? call[1] : null;
    }

    describe('entry guard', () => {
        it('draws nothing on a page carrying neither island', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await boot();

            expect(Chart).not.toHaveBeenCalled();
        });

        it('draws nothing when Chart.js was not loaded', async () => {
            window.Chart = undefined;
            dmarcPage();

            await expect(boot()).resolves.not.toThrow();
        });

        it('draws nothing when the island is there and the canvas is not', async () => {
            document.body.innerHTML = island('dmarc-trend-data', DMARC);
            await boot();

            expect(Chart).not.toHaveBeenCalled();
        });

        // The controller hands over an empty list rather than a list of holes
        // when no week is drawable, and the template then renders no canvas —
        // but an empty list must not produce a chart even if one were there.
        it('draws nothing when no week was drawable', async () => {
            dmarcPage([]);
            await boot();

            expect(Chart).not.toHaveBeenCalled();
        });

        it('draws nothing when no provider was drawable', async () => {
            seedPage({});
            await boot();

            expect(Chart).not.toHaveBeenCalled();
        });

        it('draws only the chart whose island the page carries', async () => {
            dmarcPage();
            await boot();

            expect(chartOn('dmarc-trend-chart')).not.toBeNull();
            expect(Chart).toHaveBeenCalledTimes(1);
        });
    });

    describe('a hole is not a zero', () => {
        // The whole arbitration of issue #420. A week under the threshold says
        // « we do not know »; a zero says « nothing authenticated ». Reading a
        // slope across the first would be reporting a measure nobody took.
        it('keeps an unmeasured week as null in the dataset', async () => {
            dmarcPage();
            await boot();

            expect(chartOn('dmarc-trend-chart').data.datasets[0].data).toEqual([92.3, null, 100]);
        });

        it('refuses to join the line across the hole', async () => {
            dmarcPage();
            await boot();

            expect(chartOn('dmarc-trend-chart').data.datasets[0].spanGaps).toBe(false);
        });

        it('keeps a provider\'s unmeasured week as a hole too', async () => {
            seedPage();
            await boot();

            const datasets = chartOn('seed-trend-chart').data.datasets;

            expect(datasets.map((one) => one.data)).toEqual([[100, 50], [null, 25]]);
            expect(datasets.every((one) => one.spanGaps === false)).toBe(true);
        });
    });

    describe('the figures', () => {
        it('reads a share as a percentage to one decimal', async () => {
            dmarcPage([{ label: 'Semaine', value: 0.666666, sample: 90, partial: false }]);
            await boot();

            expect(chartOn('dmarc-trend-chart').data.datasets[0].data).toEqual([66.7]);
        });

        it('labels the weeks as the server named them', async () => {
            dmarcPage();
            await boot();

            expect(chartOn('dmarc-trend-chart').data.labels).toEqual([
                '7 septembre 2026',
                '14 septembre 2026',
                '21 septembre 2026',
            ]);
        });

        // A share is not comparable to a count, and an axis fitted to the data
        // would make a one-point dip look like a collapse.
        it('fixes the scale from 0 to 100 rather than fitting it to the data', async () => {
            dmarcPage([{ label: 'Semaine', value: 0.98, sample: 90, partial: false }]);
            await boot();

            const y = chartOn('dmarc-trend-chart').options.scales.y;

            expect(y.min).toBe(0);
            expect(y.max).toBe(100);
            expect(y.ticks.callback(40)).toBe('40 %');
        });
    });

    describe('what the tooltip admits', () => {
        function afterBody(id, index) {
            return chartOn(id).options.plugins.tooltip.callbacks.afterBody([{ dataIndex: index }]);
        }

        it('says how much evidence the week carried', async () => {
            dmarcPage();
            await boot();

            expect(afterBody('dmarc-trend-chart', 0)).toBe('130 messages rapportés');
        });

        // The last point moves while the week fills. Said out loud, so a
        // half-week does not read as a drop.
        it('says so when the week is still filling up', async () => {
            dmarcPage();
            await boot();

            expect(afterBody('dmarc-trend-chart', 2)).toBe('60 messages rapportés — semaine en cours');
        });

        // Mailings, not copies: five copies of one mailing say one thing five
        // times, which is what DomainRouting::MINIMUM_RUNS refuses.
        it('counts the seed evidence in mailings', async () => {
            seedPage();
            await boot();

            expect(afterBody('seed-trend-chart', 1)).toBe('8 publipostages mesurés');
        });

        it('says nothing rather than throwing on a point it has no week for', async () => {
            dmarcPage();
            await boot();

            expect(afterBody('dmarc-trend-chart', 99)).toBe('');
        });
    });

    describe('one line per provider', () => {
        it('names each line after its provider', async () => {
            seedPage();
            await boot();

            expect(chartOn('seed-trend-chart').data.datasets.map((one) => one.label)).toEqual([
                'gmail.com',
                'outlook.com',
            ]);
        });

        // The comparison is the point: every provider is drawn against the
        // same weeks, which the server built from one window.
        it('draws every provider against the same weeks', async () => {
            seedPage();
            await boot();

            expect(chartOn('seed-trend-chart').data.labels).toEqual([
                '7 septembre 2026',
                '14 septembre 2026',
            ]);
        });

        it('gives two providers two colours', async () => {
            seedPage();
            await boot();

            const colours = chartOn('seed-trend-chart').data.datasets.map((one) => one.borderColor);

            expect(new Set(colours).size).toBe(2);
        });

        it('runs out of palette without running out of colours', async () => {
            const many = {};
            for (let i = 0; i < 12; i++) {
                many['fournisseur-' + i + '.be'] = [
                    { label: 'Semaine', value: 0.5, sample: 9, partial: false },
                ];
            }
            seedPage(many);
            await boot();

            const datasets = chartOn('seed-trend-chart').data.datasets;

            expect(datasets).toHaveLength(12);
            expect(datasets.every((one) => /^#[0-9a-f]{6}$/.test(one.borderColor))).toBe(true);
        });
    });

    // SECURITY.md § 28: nothing here builds markup from these values. A
    // provider name and a week label reach Chart.js as data; the figures a
    // reader needs in text are rendered by Twig in the <details> table.
    it('hands a hostile label to Chart.js as data and never to the DOM', async () => {
        seedPage({ '<img src=x onerror=alert(1)>': SEEDS['gmail.com'] });
        await boot();

        expect(chartOn('seed-trend-chart').data.datasets[0].label).toBe('<img src=x onerror=alert(1)>');
        expect(document.querySelector('img')).toBeNull();
    });
});
