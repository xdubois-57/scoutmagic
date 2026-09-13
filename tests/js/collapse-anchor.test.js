// Isolated JavaScript unit test — jsdom-simulated DOM only. Exercises
// the REAL implementation in public/assets/js/collapse-anchor.js
// (imported below, never reimplemented here).
//
// Bootstrap's own JS is not loaded here, and that is the contract the
// module is written to: it CLICKS the trigger rather than driving the
// Collapse API, so that Bootstrap's delegated handler keeps the button's
// `aria-expanded`, its `.collapsed` class and the chevron in step with
// the panel. What these tests assert is therefore which trigger was
// clicked — the panel's own opening is Bootstrap's job, and asserting it
// here would be asserting a mock.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const MARKUP = `
    <div class="card" id="remote-backup">
        <div class="card-body">
            <h2><button type="button" id="remote-trigger" class="collapsed"
                    data-bs-toggle="collapse" data-bs-target="#remote-backup-body"
                    aria-expanded="false">Sauvegarde hors site</button></h2>
            <div class="collapse" id="remote-backup-body">
                <p id="remote-passphrase">La phrase de passe</p>
                <button type="button" id="more-trigger" class="collapsed"
                        data-bs-toggle="collapse" data-bs-target="#remote-more">Voir plus</button>
                <div class="collapse" id="remote-more"><p id="deep">Au fond</p></div>
            </div>
        </div>
    </div>
    <div class="card" id="maintenance-health">
        <div class="card-body">
            <h2><button type="button" id="health-trigger"
                    data-bs-toggle="collapse" data-bs-target="#maintenance-health-body"
                    aria-expanded="true">État</button></h2>
            <div class="collapse show" id="maintenance-health-body">
                <p>Tâche cron réelle</p>
                <button type="button" id="health-more-trigger" class="collapsed"
                        data-bs-toggle="collapse" data-bs-target="#health-more">Afficher les précédentes</button>
                <div class="collapse" id="health-more"><p id="deep-in-open">Au fond d'une boîte ouverte</p></div>
            </div>
        </div>
    </div>`;

/** @param {string} hash @param {string} markup */
async function load(hash, markup = MARKUP) {
    document.body.innerHTML = markup;
    window.location.hash = hash;

    // scrollIntoView does not exist in jsdom; the module calls it once a
    // panel finishes opening.
    Element.prototype.scrollIntoView = vi.fn();

    /** @type {string[]} */
    const clicked = [];
    document.querySelectorAll('[data-bs-toggle="collapse"]').forEach((trigger) => {
        trigger.addEventListener('click', () => clicked.push(trigger.id));
    });

    vi.resetModules();
    await import('../../public/assets/js/collapse-anchor.js');

    return { clicked };
}

/** Bootstrap's own event, which the module waits on before scrolling. */
function shown(id) {
    document.getElementById(id).dispatchEvent(new Event('shown.bs.collapse'));
}

describe('collapse-anchor.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        window.location.hash = '';
        vi.restoreAllMocks();
    });

    it('opens the panel of the card a fragment names', async () => {
        // `/config/maintenance#remote-backup` — what
        // RemoteBackupController redirects to after every round trip
        // through Google. The fragment names the CARD; the panel to open
        // is the one inside it.
        const { clicked } = await load('#remote-backup');

        expect(clicked).toEqual(['remote-trigger']);
    });

    it('opens the panel a fragment names directly', async () => {
        const { clicked } = await load('#remote-backup-body');

        expect(clicked).toEqual(['remote-trigger']);
    });

    it('opens every folded panel between the page and a nested target', async () => {
        // Outermost first: clicking the inner trigger while its own
        // container is still closed is a click on nothing.
        const { clicked } = await load('#deep');

        expect(clicked).toEqual(['remote-trigger', 'more-trigger']);
    });

    it('leaves a panel that is already open alone', async () => {
        const { clicked } = await load('#maintenance-health');

        expect(clicked).toEqual([]);
    });

    it('leaves no listener on a section it did not have to open', async () => {
        // The shape « Mise à jour » has on the real page: it ships open
        // and holds a nested « Afficher les précédentes ».
        const { clicked } = await load('#deep-in-open');

        // Only the inner panel needed opening.
        expect(clicked).toEqual(['health-more-trigger']);

        // Bootstrap fires `shown.bs.collapse` on a panel it opens, and
        // nothing on one that was already showing — so a `{ once: true }`
        // listener registered on the outer section would never be
        // consumed. It would get its event the next time the reader
        // folded and unfolded that section BY HAND, jumping the page back
        // to a fragment they left long ago.
        shown('maintenance-health-body');
        expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();

        // The panel that did open still scrolls, as it must.
        shown('health-more');
        expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
    });

    it('scrolls to the target once the panel has finished opening', async () => {
        // Not before: the anchor has no height while the animation runs,
        // so an early scroll lands above it.
        await load('#remote-passphrase');

        expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();

        shown('remote-backup-body');

        expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
    });

    it('does nothing for a fragment naming nothing, or nothing collapsible', async () => {
        const { clicked } = await load('#pas-de-cet-ecran');
        expect(clicked).toEqual([]);

        const plain = await load('#plain', '<div id="plain"><p>rien de repliable</p></div>');
        expect(plain.clicked).toEqual([]);
    });

    it('survives a fragment that is not valid percent-encoding', async () => {
        // `decodeURIComponent('%E0%A4%A')` throws a URIError. Uncaught at
        // load time it would take the `hashchange` registration with it,
        // so one malformed fragment would stop the page reacting to every
        // well-formed one after it — which is what the second half of
        // this test proves does not happen.
        const { clicked } = await load('#%E0%A4%A');
        expect(clicked).toEqual([]);

        window.location.hash = '#remote-backup';
        window.dispatchEvent(new Event('hashchange'));

        expect(clicked).toContain('remote-trigger');
    });

    it('reacts to a fragment followed without a reload', async () => {
        const { clicked } = await load('');
        expect(clicked).toEqual([]);

        window.location.hash = '#remote-backup';
        window.dispatchEvent(new Event('hashchange'));

        // The set, not the list: every `load()` above re-imported the
        // module into this same jsdom window, and a `window` listener an
        // earlier import registered cannot be taken back off — so the
        // event reaches one listener per import. What is asserted is what
        // the module decides, which trigger a fragment reaches without a
        // reload, and each instance decides it identically.
        expect(clicked.length).toBeGreaterThan(0);
        expect(new Set(clicked)).toEqual(new Set(['remote-trigger']));
    });

    it('survives a percent-encoded fragment', async () => {
        const { clicked } = await load(
            '#sauvegarde%20hors%20site',
            `<div id="sauvegarde hors site">
                <button type="button" id="t" data-bs-toggle="collapse" data-bs-target="#p"></button>
                <div class="collapse" id="p"></div>
            </div>`
        );

        expect(clicked).toEqual(['t']);
    });
});
