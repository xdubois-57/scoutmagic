// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP
// server, no MySQL, no network. Exercises the REAL implementation in
// public/assets/js/social-publish-confirm.js (imported below, never
// reimplemented here): the composer's confirmation, destination by
// destination, issue #706, IT-05.
//
// **What only a test can see here** is which destinations the dialog
// speaks about. Which boxes are ticked is known in the browser and only
// at the moment of the click — which is why this replaced a fixed
// `data-confirm` sentence — so a server-side test cannot reach it, and
// the sentence that matters most (« avec le même message ») is the one
// that would be silently absent if the attribute stopped being read.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/** The options the dialog was opened with. */
let asked;
/** Whether the form was submitted, and with which submitter. */
let submitted;

function buildPage({ shared = true, groupsTicked = false, sharp = false } = {}) {
    document.body.innerHTML = `
        <form>
            <div data-destination="facebook" data-destination-label="Facebook"
                 ${shared ? 'data-shared-line="Cet album est déjà parti sur Facebook le 12 septembre 2026, avec le même message."' : ''}>
                <input type="checkbox" name="destinations[]" value="facebook" checked>
            </div>
            <div data-destination="instagram" data-destination-label="Instagram">
                <input type="checkbox" name="destinations[]" value="instagram" checked>
            </div>
            <div data-destination="groups">
                <input type="checkbox" name="destinations[]" value="groups" ${groupsTicked ? 'checked' : ''}>
            </div>
            <div data-group="3" data-destination-label="Groupe Pionniers"
                 data-shared-line="Déjà parti le 14 septembre 2026, avec un autre message.">
                <input type="checkbox" name="groups[]" value="3" ${groupsTicked ? 'checked' : ''}>
            </div>
            <span data-card-blur-sharp ${sharp ? '' : 'hidden'}>Cette photo de la galerie part sans flou.</span>
            <button type="submit" name="action" value="publish" data-publish-confirm>Publier</button>
        </form>
    `;

    asked = null;
    submitted = undefined;
    window.ScoutMagicConfirm = {
        ask: (options) => {
            asked = options;

            return Promise.resolve(true);
        },
    };
    // jsdom implements neither on HTMLFormElement.
    HTMLFormElement.prototype.requestSubmit = function (submitter) {
        submitted = submitter;
    };
}

async function load() {
    vi.resetModules();
    await import('../../public/assets/js/social-publish-confirm.js');
}

const button = () => document.querySelector('[data-publish-confirm]');
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => buildPage());

afterEach(() => {
    vi.restoreAllMocks();
    delete window.ScoutMagicConfirm;
    delete HTMLFormElement.prototype.requestSubmit;
});

describe('the confirmation', () => {
    it('speaks about each ticked destination, and only those', async () => {
        await load();
        button().click();
        await settle();

        expect(asked.title).toBe('Publier cette communication ?');
        expect(asked.content.map((b) => b.title)).toEqual(['Facebook', 'Instagram']);
    });

    /**
     * **The sentence the whole dialog exists for.** The identical text is
     * the case that almost always means somebody forgot they had already
     * shared it, so it is quoted in front of the chief — and marked as
     * the one block worth stopping on.
     */
    it('quotes what already went, and calls the rest never shared', async () => {
        await load();
        button().click();
        await settle();

        expect(asked.content[0].text).toContain('avec le même message');
        expect(asked.content[0].tone).toBe('warning');
        expect(asked.content[1].text).toBe('Jamais partagé ici.');
        expect(asked.content[1].tone).toBe('muted');
    });

    it('names each chosen group rather than the box that opens them', async () => {
        buildPage({ groupsTicked: true });
        await load();
        button().click();
        await settle();

        // « Groupe de discussion » is a door; what receives something is
        // the group behind it.
        expect(asked.content.map((b) => b.title)).toEqual(['Facebook', 'Instagram', 'Groupe Pionniers']);
        expect(asked.content[2].text).toBe('Déjà parti le 14 septembre 2026, avec un autre message.');
    });

    /**
     * **A destination that cannot receive anything is not announced.**
     * The composer renders an already-published or unavailable
     * destination as a ticked but DISABLED box — the browser sends none
     * of those — so a dialog reading « checked » alone would promise a
     * chief that this album is about to leave for Instagram when the
     * server will not send it there.
     */
    it('leaves out a destination whose box is disabled', async () => {
        document.querySelector('input[value="instagram"]').disabled = true;

        await load();
        button().click();
        await settle();

        expect(asked.content.map((b) => b.title)).toEqual(['Facebook']);
    });

    it('leaves the groups out when the box is not ticked', async () => {
        await load();
        button().click();
        await settle();

        expect(asked.content.map((b) => b.title)).not.toContain('Groupe Pionniers');
    });

    /**
     * Read from the hint the composer already shows under the slider, so
     * « is this photo about to leave unblurred » has one rule and not
     * two.
     */
    it('carries the sharp-photo warning when the composer is showing it', async () => {
        buildPage({ sharp: true });
        await load();
        button().click();
        await settle();

        expect(asked.content[asked.content.length - 1].text).toBe('Cette photo de la galerie part sans flou.');
    });

    it('does not carry it when the slider is not at Net', async () => {
        await load();
        button().click();
        await settle();

        expect(asked.content.map((b) => b.text).join(' ')).not.toContain('sans flou');
    });

    it('keeps saying that a publication is public and outside the site', async () => {
        await load();
        button().click();
        await settle();

        expect(asked.message).toContain('public');
        expect(asked.message).toContain('hors du site');
    });
});

describe('a page without the dialog', () => {
    /**
     * **The button stays an ordinary submit.** The composer's « Publier »
     * must work where `confirm.js` did not load — the form posts and the
     * server decides, exactly as before any confirmation existed — and
     * the retry page posts to the same controller with no such button at
     * all.
     */
    it('leaves the form alone when there is no confirmer', async () => {
        delete window.ScoutMagicConfirm;

        await load();
        button().click();
        await settle();

        expect([asked, submitted]).toEqual([null, undefined]);
    });

    it('does nothing on a page with no publish button', async () => {
        document.body.innerHTML = '<form><button type="submit">Enregistrer</button></form>';

        await load();

        expect(document.querySelector('[data-publish-confirm]')).toBeNull();
    });
});

describe('answering it', () => {
    it('submits with the publish button as the submitter', async () => {
        await load();
        button().click();
        await settle();

        // `submit()` would send no submitter, and the controller reads
        // `action=publish` from it to tell publishing from the rest.
        expect(submitted).toBe(button());
    });

    it('submits nothing when the chief says no', async () => {
        window.ScoutMagicConfirm.ask = () => Promise.resolve(false);

        await load();
        button().click();
        await settle();

        expect(submitted).toBeUndefined();
    });
});
