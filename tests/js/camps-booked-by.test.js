// Isolated JavaScript unit test — jsdom only, no PHP server, no network.
// Exercises the REAL implementation in public/assets/js/camps-booked-by.js
// (imported below, never reimplemented here): the « Réservation faite par »
// field of the camps stay form, which fills the hidden member id when the
// typed name is one of this year's staff and clears it otherwise.
//
// The file is an IIFE that reads the DOM at import time, so each test builds
// its DOM first and then imports the module via vi.resetModules() + await
// import() (the tests/js/chip-picker.test.js pattern).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const MEMBERS = [
    { id: 12, name: 'Thomas Dupont' },
    { id: 34, name: 'Élise Genin' },
];

function buildForm(members) {
    document.body.innerHTML = `
        <form>
            <div class="mb-3">
                <input type="text" id="booked-by" name="booked_by_name" value="">
            </div>
            <input type="hidden" id="booked-by-member-id" name="booked_by_member_id" value="">
            <script type="application/json" id="camps-booked-by-members">${JSON.stringify(members)}</script>
        </form>
    `;
}

async function load() {
    // Both files are IIFEs that run at import time, so the module cache has
    // to be dropped or only the first test in this file would ever wire
    // anything up.
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/camps-booked-by.js');
}

function type(value) {
    const input = /** @type {HTMLInputElement} */ (document.getElementById('booked-by'));
    input.value = value;
    input.dispatchEvent(new Event('input'));
}

function hiddenValue() {
    return /** @type {HTMLInputElement} */ (document.getElementById('booked-by-member-id')).value;
}

describe('camps-booked-by', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    describe('matchMemberId', () => {
        beforeEach(async () => {
            buildForm(MEMBERS);
            await load();
        });

        it('finds the member whose name was typed', () => {
            expect(window.ScoutMagicCampsBookedBy.matchMemberId(MEMBERS, 'Thomas Dupont')).toBe('12');
        });

        it('ignores case and stray whitespace', () => {
            expect(window.ScoutMagicCampsBookedBy.matchMemberId(MEMBERS, '  thomas   dupont '))
                .toBe('12');
        });

        it('answers nothing for a free-text name', () => {
            expect(window.ScoutMagicCampsBookedBy.matchMemberId(MEMBERS, 'le fermier'))
                .toBe('');
        });

        it('answers nothing for an empty field', () => {
            expect(window.ScoutMagicCampsBookedBy.matchMemberId(MEMBERS, '   ')).toBe('');
        });

        it('refuses to guess between two members with the same name', () => {
            const twins = [{ id: 1, name: 'Jean Martin' }, { id: 2, name: 'Jean Martin' }];

            expect(window.ScoutMagicCampsBookedBy.matchMemberId(twins, 'Jean Martin')).toBe('');
        });
    });

    describe('wiring', () => {
        it('offers every candidate as a suggestion', async () => {
            buildForm(MEMBERS);
            await load();

            const list = document.querySelector('datalist');
            expect(list).not.toBeNull();
            expect(document.getElementById('booked-by').getAttribute('list')).toBe(list.id);
            expect(Array.from(list.querySelectorAll('option')).map((o) => o.value))
                .toEqual(['Thomas Dupont', 'Élise Genin']);
        });

        it('fills the member id when the typed name is one of them', async () => {
            buildForm(MEMBERS);
            await load();

            type('Élise Genin');

            expect(hiddenValue()).toBe('34');
        });

        it('clears the member id again when the name is edited away', async () => {
            buildForm(MEMBERS);
            await load();

            type('Thomas Dupont');
            expect(hiddenValue()).toBe('12');

            // Without this half, Thomas would stay attached to a booking he
            // did not make — a wrong link nobody can see on the page.
            type('le père de Thomas');
            expect(hiddenValue()).toBe('');
        });

        it('leaves the field alone when there is nobody to suggest', async () => {
            buildForm([]);
            await load();

            expect(document.querySelector('datalist')).toBeNull();
            expect(document.getElementById('booked-by').hasAttribute('list')).toBe(false);
        });
    });
});
