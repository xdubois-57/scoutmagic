// Isolated JavaScript unit test — jsdom DOM only, no PHP/DB/network.
//
// calendar-chief.js's openEditModal() populates and reveals
// event-retro-link-wrap/event-retro-link when an event has a linked
// rétrospective. A prior version called
// retroLinkWrap.classList.remove('d-none') BEFORE setting the anchor's
// href/textContent, briefly exposing an empty, non-navigable link to a
// screen reader (SonarCloud accessibility remediation, Iteration 4). This
// test proves the anchor is always fully populated by the moment the wrap
// becomes visible.
//
// It used to extract openEditModal() out of the inline <script> of
// modules/calendar/views/chief.html.twig with a regex and rebuild it
// through new Function(), because a template's script is reachable no other
// way. That script now lives in public/assets/js/calendar-chief.js, so the
// REAL production file is imported instead and driven the way a chief
// drives it: by clicking an event bar in the month grid. The instrumented
// classList.remove() is installed on the live element after import, so it
// still observes the exact moment of the reveal.
//
// The rest of that file's behaviour (create/update/delete, the
// confirmation, the error surface) is covered by
// tests/js/calendar-chief.test.js.
import { beforeEach, describe, expect, it, vi } from 'vitest';

function buildDom() {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    document.body.innerHTML = `
        <div class="calendar-week">
            <button type="button" class="calendar-event-bar calendar-event-bar--clickable">Camp</button>
        </div>

        <div class="modal fade" id="eventModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="eventModal-title">Ajouter un évènement</h5>
                    </div>
                    <form id="event-form">
                        <div class="modal-body">
                            <input type="hidden" id="event-id" value="">
                            <input type="text" id="event-title">
                            <select id="event-calendar"><option value="1">Cal</option></select>
                            <input type="date" id="event-start-date">
                            <input type="date" id="event-end-date">
                            <input type="time" id="event-start-time">
                            <input type="time" id="event-end-time">
                            <input type="text" id="event-location">
                            <textarea id="event-description"></textarea>
                            <div class="mb-2 d-none" id="event-retro-auto-create-wrap">
                                <input type="checkbox" id="event-retro-auto-create">
                            </div>
                            <div class="mb-2 d-none small" id="event-retro-link-wrap">
                                <a href="#" id="event-retro-link" target="_blank" rel="noopener"></a>
                            </div>
                            <div class="text-danger small d-none" id="event-form-error"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="d-none" id="event-form-delete"></button>
                            <button type="submit" id="event-form-submit"></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    `;
}

/**
 * Imports the real calendar-chief.js against a fresh fixture and returns a
 * function that opens the edit dialog on one event — plus the anchor's
 * state as it was at the instant event-retro-link-wrap lost `d-none`.
 *
 * `bootstrap` is deliberately absent (the vendored bundle does not exist
 * under jsdom); calendar-chief.js shows the dialog by hand in that case.
 */
async function loadEditDialog() {
    buildDom();

    // The page's server data island. calendar-chief.js reads the calendars
    // this account may WRITE to from it and refuses to open the dialog on
    // an event outside them, so the fixture's own calendar (id 1) has to be
    // in there for the dialog to open at all.
    const island = document.createElement('script');
    island.type = 'application/json';
    island.id = 'calendar-chief-data';
    island.textContent = JSON.stringify({ editableCalendarIds: [1], defaultCalendarId: 1 });
    document.body.appendChild(island);

    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/calendar-chief.js');

    let hrefWhenRevealed;
    let textWhenRevealed;

    const retroLinkWrap = document.getElementById('event-retro-link-wrap');
    const retroLinkAnchor = document.getElementById('event-retro-link');
    const originalRemove = retroLinkWrap.classList.remove.bind(retroLinkWrap.classList);
    retroLinkWrap.classList.remove = function (...args) {
        if (args.includes('d-none')) {
            hrefWhenRevealed = retroLinkAnchor.href;
            textWhenRevealed = retroLinkAnchor.textContent;
        }
        return originalRemove(...args);
    };

    const bar = document.querySelector('.calendar-event-bar--clickable');

    return {
        /** @param {Object.<string, string>} dataset */
        openEditModal(dataset) {
            Object.assign(bar.dataset, dataset);
            bar.dispatchEvent(new Event('click', { bubbles: true }));
        },
        getStateWhenRevealed: () => ({ href: hrefWhenRevealed, text: textWhenRevealed }),
    };
}

beforeEach(() => {
    vi.resetModules();
    global.fetch = vi.fn();
    window.ScoutMagicConfirm = { ask: vi.fn(() => Promise.resolve(true)) };
    window.ScoutMagicToast = { show: vi.fn() };
    delete window.bootstrap;
    document.getElementById('calendar-chief-data')?.remove();
});

describe('calendar-chief.js openEditModal() — retro link populate-before-reveal order', () => {
    it('sets href and textContent on the retro link BEFORE revealing its wrapper', async () => {
        const { openEditModal, getStateWhenRevealed } = await loadEditDialog();

        openEditModal({
            id: '5', calendarId: '1', title: 'Camp', startDate: '2026-07-01', endDate: '2026-07-08',
            startTime: '', endTime: '', location: '', description: '',
            hasLinkedRetro: '1', autoCreateRetro: '0',
            retroLink: '/retro/9', retroLinkTitle: "Camp d'été 2026",
        });

        const { href, text } = getStateWhenRevealed();
        expect(href).toContain('/retro/9');
        expect(text).toBe("Camp d'été 2026");

        const anchor = document.getElementById('event-retro-link');
        expect(anchor.href).toContain('/retro/9');
        expect(anchor.textContent).toBe("Camp d'été 2026");
        expect(document.getElementById('event-retro-link-wrap').classList.contains('d-none')).toBe(false);
    });

    it('falls back to the raw link as the visible text when no title is given', async () => {
        const { openEditModal } = await loadEditDialog();

        openEditModal({
            id: '5', calendarId: '1', title: 'Camp', startDate: '2026-07-01', endDate: '',
            startTime: '', endTime: '', location: '', description: '',
            hasLinkedRetro: '1', autoCreateRetro: '0',
            retroLink: '/retro/9', retroLinkTitle: '',
        });

        expect(document.getElementById('event-retro-link').textContent).toBe('/retro/9');
    });

    it('hides the wrapper and leaves the link untouched when the event has no linked rétrospective', async () => {
        const { openEditModal } = await loadEditDialog();

        openEditModal({
            id: '5', calendarId: '1', title: 'Camp', startDate: '2026-07-01', endDate: '',
            startTime: '', endTime: '', location: '', description: '',
            hasLinkedRetro: '0', autoCreateRetro: '0',
            retroLink: '', retroLinkTitle: '',
        });

        expect(document.getElementById('event-retro-link-wrap').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('event-retro-link').textContent).toBe('');
    });
});
