// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no network at all: this file makes no request — its
// whole point is that the branch preview reuses data the page already
// carries. Exercises the REAL implementation in
// public/assets/js/registration-public-form.js (imported below, never
// reimplemented here). That file is an IIFE that reads the DOM at import
// time, so each test builds its fixture first and then imports the
// module via vi.resetModules() + await import().
//
// The fixture mirrors modules/registration/views/public.html.twig: the
// birth-date field, the hint line under it, and the JSON island the
// template fills with Service\SlotService::birthYearsByBranch()'s rows.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const SLOTS = [
    { birth_year: 2018, branch_label: 'Louveteaux', year_in_branch: 1, tier: 'available' },
    { birth_year: 2014, branch_label: 'Éclaireurs', year_in_branch: 2, tier: 'limited' },
    { birth_year: 2013, branch_label: 'Éclaireurs', year_in_branch: 3, tier: 'heavy' },
    { birth_year: 2012, branch_label: 'Pionniers', year_in_branch: 1, tier: null },
];

function page(data) {
    return `
        <input type="date" id="birth_date" value="">
        <div class="form-text" id="birth-date-branch-hint"></div>
        <script type="application/json" id="registration-slots-data">${JSON.stringify(data)}<\/script>`;
}

const DEFAULT_DATA = {
    birthYearSlots: SLOTS,
    waitlistEnabled: true,
    targetYearLabel: '2026-2027',
};

describe('registration-public-form.js', () => {
    beforeEach(() => {
        vi.resetModules();
        document.body.innerHTML = page(DEFAULT_DATA);
        global.fetch = vi.fn();
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/registration-public-form.js');
    }

    const field = () => document.getElementById('birth_date');
    const hint = () => document.getElementById('birth-date-branch-hint').textContent;

    function type(value) {
        field().value = value;
        field().dispatchEvent(new Event('change'));
    }

    describe('entry guard', () => {
        it('does nothing at all on a page with no birth-date field', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
        });

        it('says nothing rather than throwing when the island is unparseable', async () => {
            document.body.innerHTML = `
                <input type="date" id="birth_date" value="2018-05-04">
                <div id="birth-date-branch-hint"></div>
                <script type="application/json" id="registration-slots-data">{oops<\/script>`;
            await expect(boot()).resolves.not.toThrow();
            expect(hint()).toBe('');
        });

        it('never goes to the network — the data is already on the page', async () => {
            await boot();
            type('2018-05-04');

            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('the preview', () => {
        it('names the branch and the year in it', async () => {
            await boot();
            type('2018-05-04');

            expect(hint()).toBe('Branche prévue : Louveteaux — 1ᵉ année. Disponible.');
        });

        it('reads the birth YEAR only — the day is not known ahead of this form', async () => {
            await boot();
            type('2014-12-31');
            const december = hint();
            type('2014-01-01');

            expect(hint()).toBe(december);
            expect(hint()).toMatch(/Éclaireurs — 2ᵉ année/);
        });

        it('names each waitlist tier in French', async () => {
            await boot();

            type('2014-06-01');
            expect(hint()).toMatch(/Limitée\.$/);
            type('2013-06-01');
            expect(hint()).toMatch(/Complet\.$/);
        });

        it('says nothing about a tier when the waitlist is off', async () => {
            document.body.innerHTML = page({ ...DEFAULT_DATA, waitlistEnabled: false });
            await boot();
            type('2013-06-01');

            expect(hint()).toBe('Branche prévue : Éclaireurs — 3ᵉ année.');
        });

        it('says nothing about a tier when the row carries none', async () => {
            await boot();
            type('2012-06-01');

            expect(hint()).toBe('Branche prévue : Pionniers — 1ᵉ année.');
        });

        it('names the target year when the child is outside every branch', async () => {
            await boot();
            type('1998-06-01');

            expect(hint()).toBe("Hors des tranches d'âge de l'unité pour l'année 2026-2027.");
        });

        it('clears the line when the field is emptied', async () => {
            await boot();
            type('2018-05-04');
            expect(hint()).not.toBe('');

            type('');
            expect(hint()).toBe('');
        });

        it('says nothing on a half-typed date', async () => {
            await boot();
            type('20');

            expect(hint()).toBe('');
        });

        it('shows the line at once for a date the browser restored on reload', async () => {
            document.body.innerHTML = page(DEFAULT_DATA);
            field().value = '2018-05-04';
            await boot();

            expect(hint()).toBe('Branche prévue : Louveteaux — 1ᵉ année. Disponible.');
        });

        it('writes the branch label as TEXT, not markup', async () => {
            document.body.innerHTML = page({
                ...DEFAULT_DATA,
                birthYearSlots: [{
                    birth_year: 2018, branch_label: '<b>Louveteaux</b>', year_in_branch: 1, tier: null,
                }],
            });
            await boot();
            type('2018-05-04');

            expect(document.getElementById('birth-date-branch-hint').querySelector('b')).toBeNull();
        });
    });
});
