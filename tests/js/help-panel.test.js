// Isolated JavaScript unit test — jsdom DOM only, no PHP/DB/network.
// Exercises the REAL public/assets/js/help-panel.js (imported below, never
// reimplemented): the file is an IIFE that wires the contextual help
// offcanvas at import time.
//
// Why this file needed covering. help-panel.js exists for exactly ONE
// situation — a page several help topics cover, where the panel lands on a
// title+summary list and swaps a topic in inside the same panel. That used
// to be a handful of pages, so the script ran almost nowhere and was never
// tested. Widening `paths` across the corpus (so every page that renders
// carries help, not only the ones a menu links) turned it into ten-plus
// module pages, several of them landing on three or four topics. A script
// that only runs in the case that just became common, at 0 % coverage, is
// the wrong way round.
//
// It also carries a security control with no test behind it: topicId comes
// out of a DOM attribute and is concatenated into a URL, which is the
// js/xss-through-dom shape CodeQL flags. The allowlist that makes it safe
// is pinned here, hostile input included.
import { beforeEach, describe, expect, it, vi } from 'vitest';

async function loadPanel(html) {
    document.body.innerHTML = html;
    vi.resetModules();
    await import('../../public/assets/js/help-panel.js');
}

/**
 * The markup partials/help_panel.html.twig actually emits for the
 * multi-topic case: a list of openers, one d-none article per topic each
 * carrying a back control, and a footer link that starts invisible.
 *
 * @param {Array<{id: string, title: string}>} entries
 */
function multiTopicPanel(entries) {
    const openers = entries
        .map((e) => `<button type="button" data-help-open="${e.id}"><span>${e.title}</span></button>`)
        .join('');
    const articles = entries
        .map((e) => `<article class="help-content d-none" data-help-topic="${e.id}">`
            + '<button type="button" data-help-back>Tous les sujets de cette page</button>'
            + `<h6>${e.title}</h6></article>`)
        .join('');

    return `
        <div class="offcanvas" id="help-panel">
            <div class="offcanvas-body">
                <div data-help-list><div class="list-group">${openers}</div></div>
                ${articles}
            </div>
            <div class="offcanvas-footer">
                <a href="/aide/${entries[0].id}" class="invisible" data-help-open-full>Ouvrir dans l'aide</a>
                <a href="/aide">Tous les sujets</a>
            </div>
        </div>
    `;
}

const TWO_TOPICS = [
    { id: 'groupes', title: 'Participer aux groupes de discussion' },
    { id: 'animer-un-groupe', title: 'Créer et animer un groupe' },
];

/** @param {string} id */
function article(id) {
    return /** @type {HTMLElement} */ (document.querySelector(`[data-help-topic="${id}"]`));
}

/** @param {string} id */
function opener(id) {
    return /** @type {HTMLElement} */ (document.querySelector(`[data-help-open="${id}"]`));
}

function list() {
    return /** @type {HTMLElement} */ (document.querySelector('[data-help-list]'));
}

function fullLink() {
    return /** @type {HTMLAnchorElement} */ (document.querySelector('[data-help-open-full]'));
}

function click(element) {
    element.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('help-panel.js — the multi-topic swap', () => {
    it('opens the tapped topic and hides the list', async () => {
        await loadPanel(multiTopicPanel(TWO_TOPICS));

        click(opener('animer-un-groupe'));

        expect(article('animer-un-groupe').classList.contains('d-none')).toBe(false);
        expect(article('groupes').classList.contains('d-none')).toBe(true);
        expect(list().classList.contains('d-none')).toBe(true);
    });

    it('shows exactly one topic at a time when the visitor switches', async () => {
        await loadPanel(multiTopicPanel(TWO_TOPICS));

        click(opener('groupes'));
        click(opener('animer-un-groupe'));

        const visible = [...document.querySelectorAll('[data-help-topic]')]
            .filter((a) => !a.classList.contains('d-none'))
            .map((a) => /** @type {HTMLElement} */ (a).dataset.helpTopic);
        expect(visible).toEqual(['animer-un-groupe']);
    });

    it('brings the list back and hides every topic again', async () => {
        await loadPanel(multiTopicPanel(TWO_TOPICS));
        click(opener('groupes'));

        // The back control lives INSIDE the open article, which is the
        // only place it is reachable from.
        click(article('groupes').querySelector('[data-help-back]'));

        expect(list().classList.contains('d-none')).toBe(false);
        expect(article('groupes').classList.contains('d-none')).toBe(true);
        expect(article('animer-un-groupe').classList.contains('d-none')).toBe(true);
    });

    it('works from a click on a child element of the opener', async () => {
        // The opener is a button wrapping <span>s — the real markup puts
        // the title and summary inside it, so e.target is usually the span
        // and never the button itself.
        await loadPanel(multiTopicPanel(TWO_TOPICS));

        click(opener('groupes').querySelector('span'));

        expect(article('groupes').classList.contains('d-none')).toBe(false);
    });

    it('scrolls a freshly opened topic back to the top', async () => {
        await loadPanel(multiTopicPanel(TWO_TOPICS));
        const body = /** @type {HTMLElement} */ (document.querySelector('.offcanvas-body'));
        body.scrollTop = 240;

        click(opener('groupes'));

        expect(body.scrollTop).toBe(0);
    });
});

describe('help-panel.js — the « Ouvrir dans l\'aide » footer link', () => {
    it('points at the visible topic and becomes visible with it', async () => {
        await loadPanel(multiTopicPanel(TWO_TOPICS));
        expect(fullLink().classList.contains('invisible')).toBe(true);

        click(opener('animer-un-groupe'));

        expect(fullLink().getAttribute('href')).toBe('/aide/animer-un-groupe');
        expect(fullLink().classList.contains('invisible')).toBe(false);
    });

    it('goes back to invisible on the list, which names no single topic', async () => {
        await loadPanel(multiTopicPanel(TWO_TOPICS));
        click(opener('groupes'));

        click(article('groupes').querySelector('[data-help-back]'));

        expect(fullLink().classList.contains('invisible')).toBe(true);
    });
});

describe('help-panel.js — the topic id allowlist', () => {
    // topicId is read from a DOM attribute and concatenated into an href.
    // The server only ever writes ids matching HelpFrontMatterParser's own
    // "lowercase letters, digits and dashes" rule, but this script must not
    // depend on that: the guard is what stops a DOM-sourced string becoming
    // a URL. Each hostile shape below must fall back to /aide rather than
    // reach the href.
    const REJECTED = [
        ['a scheme', 'javascript:alert(1)'],
        ['a traversal', '../../admin'],
        ['a protocol-relative host', '//evil.example'],
        ['an absolute path', '/admin/members'],
        ['a query string', 'aide?next=//evil.example'],
        ['a fragment', 'aide#x'],
        ['uppercase', 'Aide'],
        ['whitespace', 'mon compte'],
        ['empty', ''],
    ];

    it.each(REJECTED)('refuses %s and falls back to /aide', async (_label, hostileId) => {
        await loadPanel(`
            <div class="offcanvas" id="help-panel">
                <div class="offcanvas-body">
                    <div data-help-list>
                        <button type="button" data-help-open="${hostileId}">Sujet</button>
                    </div>
                    <article class="d-none" data-help-topic="${hostileId}"></article>
                </div>
                <a href="/aide/x" class="invisible" data-help-open-full>Ouvrir dans l'aide</a>
            </div>
        `);

        click(document.querySelector('[data-help-open]'));

        expect(fullLink().getAttribute('href')).toBe('/aide');
    });

    it('accepts the ids the parser really produces', async () => {
        await loadPanel(multiTopicPanel([
            { id: 'suivre-une-demande', title: 'Suivre' },
            { id: 'camps2026', title: 'Camps' },
        ]));

        click(opener('camps2026'));
        expect(fullLink().getAttribute('href')).toBe('/aide/camps2026');

        click(opener('suivre-une-demande'));
        expect(fullLink().getAttribute('href')).toBe('/aide/suivre-une-demande');
    });
});

describe('help-panel.js — DOM shapes it must survive', () => {
    it('does nothing at all when the page carries no help panel', async () => {
        // Most pages render no panel (base.html.twig includes it only when
        // route_help is non-empty), so importing into a bare document is
        // the COMMON case, not an edge one.
        await expect(loadPanel('<main>Une page sans aide</main>')).resolves.toBeUndefined();
    });

    it('leaves a single-topic panel alone', async () => {
        // One topic → no list, no back control, and the footer link is
        // already correct from the server. Nothing should hide the article.
        await loadPanel(`
            <div class="offcanvas" id="help-panel">
                <div class="offcanvas-body">
                    <article class="help-content" data-help-topic="aide"><h6>Aide</h6></article>
                </div>
                <a href="/aide/aide" data-help-open-full>Ouvrir dans l'aide</a>
            </div>
        `);

        expect(article('aide').classList.contains('d-none')).toBe(false);
        expect(fullLink().getAttribute('href')).toBe('/aide/aide');
    });

    it('still swaps topics when the footer link is absent', async () => {
        await loadPanel(`
            <div class="offcanvas" id="help-panel">
                <div class="offcanvas-body">
                    <div data-help-list><button type="button" data-help-open="groupes">Groupes</button></div>
                    <article class="d-none" data-help-topic="groupes"></article>
                </div>
            </div>
        `);

        click(document.querySelector('[data-help-open]'));

        expect(article('groupes').classList.contains('d-none')).toBe(false);
    });

    it('ignores a click that is neither an opener nor the back control', async () => {
        await loadPanel(multiTopicPanel(TWO_TOPICS));

        click(document.querySelector('.offcanvas-body'));

        expect(list().classList.contains('d-none')).toBe(false);
        expect(article('groupes').classList.contains('d-none')).toBe(true);
    });
});
