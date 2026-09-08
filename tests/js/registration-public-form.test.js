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
    { birth_year: 2018, age_branch_id: 2, branch_label: 'Louveteaux', year_in_branch: 1, tier: 'available' },
    { birth_year: 2014, age_branch_id: 3, branch_label: 'Éclaireurs', year_in_branch: 2, tier: 'limited' },
    { birth_year: 2013, age_branch_id: 3, branch_label: 'Éclaireurs', year_in_branch: 3, tier: 'heavy' },
    { birth_year: 2012, age_branch_id: 4, branch_label: 'Pionniers', year_in_branch: 1, tier: null },
];

// The « Section souhaitée » list as modules/registration/views/public.html.twig
// writes it: « Aucune préférence » with no branch, then one option per
// section carrying the branch it belongs to.
const SECTIONS = [
    { id: 11, branch: 2, label: 'Louveteaux — Meute A' },
    { id: 12, branch: 2, label: 'Louveteaux — Meute B' },
    { id: 21, branch: 3, label: 'Éclaireurs — Troupe' },
    { id: 31, branch: 4, label: 'Pionniers — Poste' },
];

function sectionOptions() {
    return SECTIONS
        .map((s) => `<option value="${s.id}" data-branch-id="${s.branch}">${s.label}</option>`)
        .join('');
}

function page(data) {
    return `
        <input type="date" id="birth_date" value="">
        <div class="form-text" id="birth-date-branch-hint"></div>
        <select id="desired_section_id" name="desired_section_id">
            <option value="">Aucune préférence</option>
            ${sectionOptions()}
        </select>
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
    const sections = () => document.getElementById('desired_section_id');

    /** The labels a parent can actually pick, in order. */
    const offered = () => Array.from(sections().options)
        .filter((option) => !option.hidden && !option.disabled)
        .map((option) => option.textContent.trim());

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

        it('leaves the section list alone until a branch is known', async () => {
            await boot();

            expect(offered()).toHaveLength(SECTIONS.length + 1);
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

    // The list of sections a parent may ask for, narrowed to the branch
    // the birth date lands in. The server already refuses a section from
    // another branch — and replaces it with « Aucune préférence » without
    // a word, which is what made the full list a real defect rather than
    // an untidy one (issue #264).
    describe('the « Section souhaitée » list', () => {
        it('keeps only the sections of the branch the birth date lands in', async () => {
            await boot();
            type('2018-05-04');

            expect(offered()).toEqual([
                'Aucune préférence',
                'Louveteaux — Meute A',
                'Louveteaux — Meute B',
            ]);
        });

        it('follows a corrected birth date from one branch to another', async () => {
            await boot();
            type('2018-05-04');
            type('2012-05-04');

            expect(offered()).toEqual(['Aucune préférence', 'Pionniers — Poste']);
        });

        it('always offers « Aucune préférence » — not choosing is valid at every age', async () => {
            await boot();
            type('2014-05-04');

            expect(offered()).toContain('Aucune préférence');
        });

        it('hides AND disables what it removes, because Safari has ignored one of the two', async () => {
            await boot();
            type('2018-05-04');

            const pionniers = Array.from(sections().options)
                .find((option) => option.value === '31');

            expect(pionniers.hidden).toBe(true);
            expect(pionniers.disabled).toBe(true);
        });

        it('drops a selection the corrected date has just invalidated', async () => {
            await boot();
            type('2018-05-04');
            sections().value = '12';

            type('2012-05-04');

            expect(sections().value).toBe('');
        });

        it('keeps a selection the corrected date leaves valid', async () => {
            await boot();
            type('2018-05-04');
            sections().value = '12';

            // Another Louveteaux birth year: same branch, same offer.
            type('2018-11-30');

            expect(sections().value).toBe('12');
        });

        it('puts the whole list back when the birth date is cleared', async () => {
            await boot();
            type('2018-05-04');
            expect(offered()).toHaveLength(3);

            type('');

            expect(offered()).toHaveLength(SECTIONS.length + 1);
        });

        it('puts the whole list back for a year outside every branch, rather than emptying it', async () => {
            await boot();
            type('1998-06-01');

            expect(offered()).toHaveLength(SECTIONS.length + 1);
        });

        it('does nothing at all on a page that has no such list', async () => {
            document.body.innerHTML = `
                <input type="date" id="birth_date" value="">
                <div id="birth-date-branch-hint"></div>
                <script type="application/json" id="registration-slots-data">${JSON.stringify(DEFAULT_DATA)}<\/script>`;
            await boot();

            expect(() => type('2018-05-04')).not.toThrow();
            expect(hint()).toBe('Branche prévue : Louveteaux — 1ᵉ année. Disponible.');
        });

        it('offers every section when the row carries no branch id', async () => {
            document.body.innerHTML = page({
                ...DEFAULT_DATA,
                birthYearSlots: [{
                    birth_year: 2018, branch_label: 'Louveteaux', year_in_branch: 1, tier: null,
                }],
            });
            await boot();
            type('2018-05-04');

            expect(offered()).toHaveLength(SECTIONS.length + 1);
        });
    });
});
