// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no real network: fetch is mocked, and so is the
// site-wide toast toolbox. Exercises the REAL implementation in
// public/assets/js/presences-lines.js (imported below, never
// reimplemented here). That file is an IIFE reading the DOM at import
// time, so each test builds its fixture first and then imports it via
// vi.resetModules() + await import().
//
// Two fixtures, because the file drives two screens off the same partial:
// SHEET mirrors modules/presences/views/sheet.html.twig (one row per
// animé, four counters that filter) and ANIME mirrors views/anime.html.twig
// (one row per date, the same animé throughout, no counters at all, and a
// notice saying the figures above have gone stale).
import { beforeEach, describe, expect, it, vi } from 'vitest';

const STATUSES = [
    ['present', 'success'],
    ['excused', 'warning'],
    ['absent', 'danger'],
    ['unset', 'secondary'],
];

function tile([status, tone]) {
    return `
        <button type="button" class="btn btn-outline-secondary presence-filter"
                data-status="${status}" data-tone="${tone}" aria-pressed="false">
            <span class="presence-filter-count">0</span>
        </button>`;
}

// The shared row, exactly as @presences/partials/_presence_line.html.twig
// renders it: its own endpoint, its own row id, and the animé it writes.
function line({ rowId, memberId, endpoint, status, comment = null }) {
    const buttons = STATUSES.map(([value, tone]) => `
        <button type="button" data-status="${value}" data-tone="${tone}"
                class="btn presence-state ${value === status ? 'btn-' + tone : 'btn-outline-secondary'}"
                aria-pressed="${value === status}"></button>`).join('');

    return `
        <article class="card presence-line" data-row-id="${rowId}" data-member-id="${memberId}"
                 data-endpoint="${endpoint}" data-status="${status}">
            <div class="card-body">
                ${buttons}
                <div class="presence-comment-panel${comment ? '' : ' d-none'}">
                    <textarea class="presence-comment">${comment ?? ''}</textarea>
                </div>
                <button type="button" class="presence-comment-toggle${comment ? ' d-none' : ''}"></button>
            </div>
        </article>`;
}

const SHEET_ENDPOINT = '/chefs/presences/feuille/7/enregistrer';

function sheetLine(memberId, status, comment = null) {
    return line({ rowId: memberId, memberId, endpoint: SHEET_ENDPOINT, status, comment });
}

const SHEET = `
    <div id="presences-counters">${STATUSES.map(tile).join('')}</div>
    <p class="d-none" id="presences-filter-bar">
        <button type="button" id="presences-clear-filter"><span id="presences-filter-summary"></span></button>
    </p>
    <div id="presences-lines">
        ${sheetLine('11', 'present')}
        ${sheetLine('12', 'unset')}
        ${sheetLine('13', 'unset', 'Malade.')}
    </div>`;

// One animé, three dates: the member id repeats and the endpoint does not.
const ANIME = `
    <output class="alert d-none" id="presences-stale"></output>
    <div id="presences-lines">
        ${line({ rowId: '31', memberId: '12', endpoint: '/chefs/presences/feuille/31/enregistrer', status: 'present' })}
        ${line({ rowId: '32', memberId: '12', endpoint: '/chefs/presences/feuille/32/enregistrer', status: 'unset' })}
        ${line({ rowId: '33', memberId: '12', endpoint: '/chefs/presences/feuille/33/enregistrer', status: 'absent', comment: 'Malade.' })}
    </div>`;

function jsonResponse(body, status = 200) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('presences-lines.js', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.useRealTimers();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = SHEET;
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        window.ScoutMagicToast = { show: vi.fn() };
    });

    async function boot() {
        await import('../../public/assets/js/api.js');
        await import('../../public/assets/js/presences-lines.js');
    }

    const row = (id) => document.querySelector(`.presence-line[data-row-id="${id}"]`);
    const stateButton = (id, status) => row(id).querySelector(`.presence-state[data-status="${status}"]`);
    const tileFor = (status) => document.querySelector(`.presence-filter[data-status="${status}"]`);
    const countOf = (status) => tileFor(status).querySelector('.presence-filter-count').textContent;
    const lastRequest = () => {
        const [url, opts] = fetch.mock.calls[fetch.mock.calls.length - 1];
        return { url, body: JSON.parse(opts.body) };
    };
    const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

    describe('entry guard', () => {
        it('does nothing at all on a page with no presence rows', async () => {
            document.body.innerHTML = '<p>Une autre page</p>';
            await expect(boot()).resolves.not.toThrow();
            expect(fetch).not.toHaveBeenCalled();
        });
    });

    describe('the counters', () => {
        it('counts what is on the page rather than what the server last said', async () => {
            await boot();

            expect(countOf('present')).toBe('1');
            expect(countOf('unset')).toBe('2');
            expect(countOf('absent')).toBe('0');
        });

        it('dims and disables a tile nobody is in', async () => {
            await boot();

            expect(tileFor('absent').disabled).toBe(true);
            expect(tileFor('absent').classList.contains('opacity-50')).toBe(true);
            expect(tileFor('present').disabled).toBe(false);
        });

        it('follows every tap without a page reload', async () => {
            await boot();

            stateButton('12', 'absent').click();
            await flush();

            expect(countOf('absent')).toBe('1');
            expect(countOf('unset')).toBe('1');
        });
    });

    describe('tapping a state', () => {
        it('saves it immediately, with the animé and the CSRF token', async () => {
            await boot();

            stateButton('12', 'excused').click();
            await flush();

            expect(lastRequest().url).toBe('/chefs/presences/feuille/7/enregistrer');
            expect(lastRequest().body).toEqual({
                member_id: 12,
                status: 'excused',
                _csrf_token: 'tok-123',
            });
        });

        it('never sends a comment alongside a state', async () => {
            await boot();

            stateButton('13', 'absent').click();
            await flush();

            expect(lastRequest().body).not.toHaveProperty('comment');
        });

        it('fills the chosen button with its own colour and clears the others', async () => {
            await boot();

            stateButton('12', 'absent').click();
            await flush();

            expect(stateButton('12', 'absent').classList.contains('btn-danger')).toBe(true);
            expect(stateButton('12', 'absent').getAttribute('aria-pressed')).toBe('true');
            expect(stateButton('12', 'unset').classList.contains('btn-outline-secondary')).toBe(true);
            expect(stateButton('12', 'unset').getAttribute('aria-pressed')).toBe('false');
        });

        it('does not re-save a state the animé is already in', async () => {
            await boot();

            stateButton('11', 'present').click();
            await flush();

            expect(fetch).not.toHaveBeenCalled();
        });

        it('puts the line back and says so when the server refuses', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Pas la vôtre.' }, 403));
            await boot();

            stateButton('12', 'absent').click();
            await flush();

            expect(row('12').dataset.status).toBe('unset');
            expect(stateButton('12', 'unset').classList.contains('btn-secondary')).toBe(true);
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                'Pas la vôtre.',
                { variant: 'error' }
            );
        });

        it('says the tap was not recorded when the network is down', async () => {
            global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
            await boot();

            stateButton('12', 'absent').click();
            await flush();

            expect(row('12').dataset.status).toBe('unset');
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                expect.stringContaining("n'a pas été enregistré"),
                { variant: 'error' }
            );
        });
    });

    describe('filtering by a counter', () => {
        it('shows only that state, and says how many of how many', async () => {
            await boot();

            tileFor('unset').click();

            expect(row('11').classList.contains('d-none')).toBe(true);
            expect(row('12').classList.contains('d-none')).toBe(false);
            expect(document.getElementById('presences-filter-bar').classList.contains('d-none')).toBe(false);
            expect(document.getElementById('presences-filter-summary').textContent)
                .toBe('2 affichés sur 3 — tout revoir');
        });

        it('fills the active tile with its colour, and stays legible at zero', async () => {
            await boot();

            tileFor('unset').click();

            expect(tileFor('unset').classList.contains('btn-secondary')).toBe(true);
            expect(tileFor('unset').getAttribute('aria-pressed')).toBe('true');
        });

        it('lets an animé leave the list when their state changes under it', async () => {
            await boot();
            tileFor('unset').click();

            stateButton('12', 'present').click();
            await flush();

            expect(row('12').classList.contains('d-none')).toBe(true);
            expect(document.getElementById('presences-filter-summary').textContent)
                .toBe('1 affiché sur 3 — tout revoir');
        });

        it('is turned off by a second tap on the same tile', async () => {
            await boot();

            tileFor('unset').click();
            tileFor('unset').click();

            expect(row('11').classList.contains('d-none')).toBe(false);
            expect(document.getElementById('presences-filter-bar').classList.contains('d-none')).toBe(true);
        });

        it('is turned off by the « tout revoir » bar', async () => {
            await boot();
            tileFor('unset').click();

            document.getElementById('presences-clear-filter').click();

            expect(row('11').classList.contains('d-none')).toBe(false);
            expect(document.getElementById('presences-filter-bar').classList.contains('d-none')).toBe(true);
        });
    });

    describe('the comment', () => {
        it('is folded away until asked for, and open when there is one', async () => {
            await boot();

            expect(row('12').querySelector('.presence-comment-panel').classList.contains('d-none')).toBe(true);
            expect(row('13').querySelector('.presence-comment-panel').classList.contains('d-none')).toBe(false);
            expect(row('13').querySelector('.presence-comment-toggle').classList.contains('d-none')).toBe(true);
        });

        it('opens on « Ajouter un commentaire » and takes the focus', async () => {
            await boot();

            row('12').querySelector('.presence-comment-toggle').click();

            expect(row('12').querySelector('.presence-comment-panel').classList.contains('d-none')).toBe(false);
            expect(document.activeElement).toBe(row('12').querySelector('.presence-comment'));
        });

        it('saves on blur, alone, without touching the state', async () => {
            await boot();

            const field = row('13').querySelector('.presence-comment');
            field.value = 'Prévenu jeudi.';
            field.dispatchEvent(new Event('focusout', { bubbles: true }));
            await flush();

            expect(lastRequest().body).toEqual({
                member_id: 13,
                comment: 'Prévenu jeudi.',
                _csrf_token: 'tok-123',
            });
        });


        it('sends nothing when a field nobody edited loses the focus', async () => {
            // Opening the panel focuses the field, so tapping a state
            // button right after raises a focusout on an untouched
            // comment. Posting it would re-stamp the row for nothing —
            // and replace a comment somebody else wrote since this page
            // loaded with the text this page still remembers.
            await boot();
            fetch.mockClear();

            row('12').querySelector('.presence-comment-toggle').click();
            const field = row('12').querySelector('.presence-comment');
            field.dispatchEvent(new Event('focusout', { bubbles: true }));
            await flush();

            expect(fetch).not.toHaveBeenCalled();
        });

        it('retries on the next blur when the server refused the comment', async () => {
            // The failure this guards: marking the text saved before the
            // server answers makes the next blur see an unchanged field
            // and skip the retry — so a comment the server never received
            // sits on screen looking written, and is gone on reload.
            await boot();
            global.fetch = vi.fn(() => Promise.resolve({
                ok: false,
                status: 500,
                json: () => Promise.resolve({ success: false, error: 'Erreur.' }),
            }));

            const field = row('13').querySelector('.presence-comment');
            field.value = 'Prévenu jeudi.';
            field.dispatchEvent(new Event('focusout', { bubbles: true }));
            await flush();
            expect(fetch).toHaveBeenCalledTimes(1);

            // Same text, untouched — but never acknowledged, so it goes again.
            field.dispatchEvent(new Event('focusout', { bubbles: true }));
            await flush();
            expect(fetch).toHaveBeenCalledTimes(2);
        });

        it('sends nothing on a second blur when the text has not moved since', async () => {
            await boot();

            const field = row('13').querySelector('.presence-comment');
            field.value = 'Prévenu jeudi.';
            field.dispatchEvent(new Event('focusout', { bubbles: true }));
            await flush();
            fetch.mockClear();

            field.dispatchEvent(new Event('focusout', { bubbles: true }));
            await flush();

            expect(fetch).not.toHaveBeenCalled();
        });

        it('debounces typing per row, so one comment never cancels another', async () => {
            vi.useFakeTimers();
            await boot();

            const first = row('13').querySelector('.presence-comment');
            first.value = 'Malade depuis mardi.';
            first.dispatchEvent(new Event('input', { bubbles: true }));

            row('12').querySelector('.presence-comment-toggle').click();
            const second = row('12').querySelector('.presence-comment');
            second.value = 'Rentre plus tôt.';
            second.dispatchEvent(new Event('input', { bubbles: true }));

            vi.advanceTimersByTime(1000);
            await vi.runAllTimersAsync();

            const sent = fetch.mock.calls.map(([, opts]) => JSON.parse(opts.body));
            expect(sent).toEqual([
                { member_id: 13, comment: 'Malade depuis mardi.', _csrf_token: 'tok-123' },
                { member_id: 12, comment: 'Rentre plus tôt.', _csrf_token: 'tok-123' },
            ]);
        });

        it('does not send the same text twice when a debounce is pending at blur', async () => {
            vi.useFakeTimers();
            await boot();

            // A value that really differs from the one the page loaded —
            // an untouched field sends nothing at all, which is the test
            // two rows below.
            const field = row('13').querySelector('.presence-comment');
            field.value = 'Malade depuis mardi.';
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('focusout', { bubbles: true }));

            vi.advanceTimersByTime(2000);
            await vi.runAllTimersAsync();

            expect(fetch).toHaveBeenCalledTimes(1);
        });
    });

    // The animé's page: the same rows, one per date, with no counters to
    // recount and no filter to apply. Everything the sheet does with them
    // has to keep working with none of that on the page.
    describe("on an animé's page", () => {
        beforeEach(() => {
            document.body.innerHTML = ANIME;
        });

        it('writes each date to its own evening, with the animé of the page', async () => {
            await boot();

            stateButton('32', 'present').click();
            await flush();

            expect(lastRequest().url).toBe('/chefs/presences/feuille/32/enregistrer');
            expect(lastRequest().body).toEqual({
                member_id: 12,
                status: 'present',
                _csrf_token: 'tok-123',
            });
        });

        it('paints the tapped date without a counter to lean on', async () => {
            await boot();

            stateButton('32', 'excused').click();
            await flush();

            expect(row('32').dataset.status).toBe('excused');
            expect(stateButton('32', 'excused').classList.contains('btn-warning')).toBe(true);
            expect(row('31').dataset.status).toBe('present');
        });

        it('puts a refused date back, exactly as a sheet does', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Pas la vôtre.' }, 403));
            await boot();

            stateButton('32', 'absent').click();
            await flush();

            expect(row('32').dataset.status).toBe('unset');
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Pas la vôtre.', { variant: 'error' });
        });

        it('says the rates above have stopped matching the rows, once something moves', async () => {
            await boot();
            const notice = document.getElementById('presences-stale');
            expect(notice.classList.contains('d-none')).toBe(true);

            stateButton('32', 'present').click();
            await flush();

            expect(notice.classList.contains('d-none')).toBe(false);
        });

        it('leaves the notice alone when the server refused the change', async () => {
            global.fetch = vi.fn(() => jsonResponse({ success: false, error: 'Pas la vôtre.' }, 403));
            await boot();

            stateButton('32', 'present').click();
            await flush();

            expect(document.getElementById('presences-stale').classList.contains('d-none')).toBe(true);
        });

        it('debounces one comment per DATE, not per animé', async () => {
            // Every row here carries the same member id, so a debounce
            // keyed on it would have the second date cancel the first
            // date's pending save — and nothing on screen would say the
            // first was lost.
            vi.useFakeTimers();
            await boot();

            const first = row('33').querySelector('.presence-comment');
            first.value = 'Malade depuis mardi.';
            first.dispatchEvent(new Event('input', { bubbles: true }));

            row('32').querySelector('.presence-comment-toggle').click();
            const second = row('32').querySelector('.presence-comment');
            second.value = 'Rentre plus tôt.';
            second.dispatchEvent(new Event('input', { bubbles: true }));

            vi.advanceTimersByTime(1000);
            await vi.runAllTimersAsync();

            const sent = fetch.mock.calls.map(([url, opts]) => [url, JSON.parse(opts.body).comment]);
            expect(sent).toEqual([
                ['/chefs/presences/feuille/33/enregistrer', 'Malade depuis mardi.'],
                ['/chefs/presences/feuille/32/enregistrer', 'Rentre plus tôt.'],
            ]);
        });
    });

    describe('the endpoint a row carries', () => {
        // Read off the DOM, so it is validated at the sink rather than
        // trusted for having been rendered by our own template. Every
        // form below LOOKS like a path and resolves to another origin:
        // WHATWG's parser reads `\\` as a second slash, and strips tab,
        // LF and CR before parsing at all. They are the reason the guard
        // resolves the value instead of spelling out characters.
        it.each([
            ['a protocol-relative URL', '//ailleurs.example/vol'],
            ['a backslash the URL parser reads as a second slash', '/\\ailleurs.example/vol'],
            ['a tab the URL parser strips before parsing', '/\t/ailleurs.example/vol'],
            ['a newline the URL parser strips before parsing', '/\n/ailleurs.example/vol'],
            ['a carriage return the URL parser strips before parsing', '/\r/ailleurs.example/vol'],
            ['an absolute URL', 'https://ailleurs.example/vol'],
        ])('is never posted to when it is %s', async (_label, endpoint) => {
            document.body.innerHTML = `<div id="presences-lines">${line({
                rowId: '41', memberId: '12', endpoint, status: 'unset',
            })}</div>`;
            await boot();

            stateButton('41', 'present').click();
            await flush();

            expect(fetch).not.toHaveBeenCalled();
            expect(row('41').dataset.status).toBe('unset');
            expect(window.ScoutMagicToast.show).toHaveBeenCalledWith(
                expect.stringContaining('Erreur'),
                { variant: 'error' }
            );
        });
    });
});
