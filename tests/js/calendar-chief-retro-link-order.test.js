// Isolated JavaScript unit test — jsdom DOM only, no PHP/DB/network.
//
// modules/calendar/views/chief.html.twig's openEditModal() populates and
// reveals event-retro-link-wrap/event-retro-link when an event has a linked
// rétrospective. A prior version called
// retroLinkWrap.classList.remove('d-none') BEFORE setting the anchor's
// href/textContent, briefly exposing an empty, non-navigable link to a
// screen reader (SonarCloud accessibility remediation, Iteration 4). This
// test extracts the REAL openEditModal() out of the template source
// (never reimplemented here — same technique as
// tests/js/escape-html-attribute-safety.test.js) and proves the anchor is
// always fully populated by the moment the wrap becomes visible.
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const templatePath = resolve(here, '../../modules/calendar/views/chief.html.twig');
const source = readFileSync(templatePath, 'utf8');

function extract(pattern, label) {
    const match = source.match(pattern);
    if (match === null) {
        throw new Error(`Could not find ${label} in ${templatePath} — has the script been restructured?`);
    }
    return match[0];
}

// The top-level `const xxxInput = document.getElementById(...)` block —
// plain JS, no Twig interpolation, unlike defaultTitle/defaultStartTime/etc.
// just below it.
const constDeclarations = extract(
    /const eventModalEl[\s\S]*?const retroLinkAnchor = document\.getElementById\('event-retro-link'\);/,
    'the eventModalEl..retroLinkAnchor const block'
);

const openEditModalFn = extract(/function openEditModal\(dataset\) \{[\s\S]*?\n\}/, 'openEditModal()');

function buildDom() {
    document.body.innerHTML = `
        <div class="modal fade" id="eventModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="event-modal-title">Ajouter un évènement</h5>
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
 * Builds a fresh openEditModal() bound to the current DOM, with `bootstrap`
 * stubbed out (chief.html.twig relies on the real Bootstrap bundle, not
 * under test here) and instrumented so the test can observe the anchor's
 * state at the exact moment event-retro-link-wrap loses d-none.
 */
function loadOpenEditModal() {
    let hrefWhenRevealed;
    let textWhenRevealed;

    const factory = new Function(
        'bootstrap',
        'onRevealRetroLink',
        `
        ${constDeclarations}
        const originalRemove = retroLinkWrap.classList.remove.bind(retroLinkWrap.classList);
        retroLinkWrap.classList.remove = function (...args) {
            if (args.includes('d-none')) {
                onRevealRetroLink(retroLinkAnchor.href, retroLinkAnchor.textContent);
            }
            return originalRemove(...args);
        };
        ${openEditModalFn}
        return openEditModal;
        `
    );

    const shownModals = [];
    const stubBootstrap = { Modal: function (el) { this.show = () => shownModals.push(el); } };
    const openEditModal = factory(stubBootstrap, (href, text) => {
        hrefWhenRevealed = href;
        textWhenRevealed = text;
    });

    return {
        openEditModal,
        shownModals,
        getStateWhenRevealed: () => ({ href: hrefWhenRevealed, text: textWhenRevealed }),
    };
}

describe('chief.html.twig openEditModal() — retro link populate-before-reveal order', () => {
    it('sets href and textContent on the retro link BEFORE revealing its wrapper', () => {
        buildDom();
        const { openEditModal, getStateWhenRevealed } = loadOpenEditModal();

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

    it('falls back to the raw link as the visible text when no title is given', () => {
        buildDom();
        const { openEditModal } = loadOpenEditModal();

        openEditModal({
            id: '5', calendarId: '1', title: 'Camp', startDate: '2026-07-01', endDate: '',
            startTime: '', endTime: '', location: '', description: '',
            hasLinkedRetro: '1', autoCreateRetro: '0',
            retroLink: '/retro/9', retroLinkTitle: '',
        });

        expect(document.getElementById('event-retro-link').textContent).toBe('/retro/9');
    });

    it('hides the wrapper and leaves the link untouched when the event has no linked rétrospective', () => {
        buildDom();
        const { openEditModal } = loadOpenEditModal();

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
