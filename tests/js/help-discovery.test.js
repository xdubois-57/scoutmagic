// Isolated JavaScript unit test — jsdom DOM only, no PHP/DB/network.
// Exercises the REAL public/assets/js/help-discovery.js (imported below,
// never reimplemented): the file is an IIFE that wires the « Le
// saviez-vous ? » modal at import time.
//
// Three things are worth a test here and nothing else would catch any of
// them, because the dialog renders correctly whatever this script gets
// wrong:
//
//  - the walk between cards, which is the whole component;
//  - the ids ACTUALLY gone past, which is what the server records — send
//    the whole batch and somebody loses four tips they never saw;
//  - ONE network call, at the close. The dismissal fires an event AND a
//    button may have already spoken; a second, contradicting call would
//    quietly overwrite the first (« Ne plus me proposer » followed by an
//    ordinary « close » is the case that matters).
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The markup partials/help_discovery_dialog.html.twig actually emits,
 * reduced to the attributes the script reads.
 *
 * @param {string[]} ids
 * @param {boolean} more
 */
function dialog(ids, more = false) {
    const cards = ids
        .map((id, i) => `<div class="${i === 0 ? '' : 'd-none'}" data-discovery-card data-discovery-id="${id}">`
            + `<p>Question ${id} ?</p></div>`)
        .join('');

    return `
        <div class="modal" id="help-discovery-modal">
            <div class="modal-body">
                <p data-discovery-position></p>
                ${cards}
            </div>
            <div class="modal-footer">
                <button type="button" data-discovery-next>Suivant</button>
                <button type="button" class="d-none" data-discovery-done>Terminé</button>
                ${more ? '<button type="button" class="d-none" data-discovery-more>Voir d\'autres astuces</button>' : ''}
                <button type="button" data-discovery-snooze>Pas avant une semaine</button>
                <button type="button" data-discovery-never>Ne plus me proposer</button>
            </div>
        </div>`;
}

let posted;
let shown;
let hidden;

/**
 * @param {string} html
 */
async function load(html) {
    document.body.innerHTML = html;
    posted = [];
    shown = 0;
    hidden = 0;

    window.ScoutMagicApi = /** @type {any} */ ({
        postJson: (url, body) => {
            posted.push({ url, body });
            return Promise.resolve({ ok: true, status: 200, data: { success: true } });
        },
    });

    const instance = {
        show: () => { shown += 1; },
        hide: () => {
            hidden += 1;
            document.getElementById('help-discovery-modal')
                .dispatchEvent(new Event('hidden.bs.modal'));
        },
    };
    window.bootstrap = /** @type {any} */ ({
        Modal: {
            getOrCreateInstance: () => instance,
            getInstance: () => instance,
        },
    });

    vi.resetModules();
    await import('../../public/assets/js/help-discovery.js');
}

/** @param {string} attribute */
function click(attribute) {
    document.querySelector(`[${attribute}]`).dispatchEvent(new Event('click'));
}

function visibleCardId() {
    const card = Array.from(document.querySelectorAll('[data-discovery-card]'))
        .find((c) => !c.classList.contains('d-none'));
    return card ? card.getAttribute('data-discovery-id') : null;
}

function position() {
    return document.querySelector('[data-discovery-position]').textContent;
}

describe('help-discovery.js', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('does nothing at all on a page carrying no dialog', async () => {
        await load('<main>Une page ordinaire</main>');

        expect(shown).toBe(0);
        expect(posted).toEqual([]);
    });

    it('opens on the first card and shows the position', async () => {
        await load(dialog(['publipostage', 'camps-encoder', 'mes-paiements']));

        expect(shown).toBe(1);
        expect(visibleCardId()).toBe('publipostage');
        expect(position()).toBe('1 / 3');
    });

    it('walks the cards in place', async () => {
        await load(dialog(['un', 'deux', 'trois']));

        click('data-discovery-next');
        expect(visibleCardId()).toBe('deux');
        expect(position()).toBe('2 / 3');

        click('data-discovery-next');
        expect(visibleCardId()).toBe('trois');
        expect(position()).toBe('3 / 3');
    });

    it('swaps « Suivant » for « Terminé » on the last card, and only there', async () => {
        await load(dialog(['un', 'deux'], true));

        const next = document.querySelector('[data-discovery-next]');
        const done = document.querySelector('[data-discovery-done]');
        const more = document.querySelector('[data-discovery-more]');

        expect(next.classList.contains('d-none')).toBe(false);
        expect(done.classList.contains('d-none')).toBe(true);
        expect(more.classList.contains('d-none')).toBe(true);

        click('data-discovery-next');

        expect(next.classList.contains('d-none')).toBe(true);
        expect(done.classList.contains('d-none')).toBe(false);
        expect(more.classList.contains('d-none')).toBe(false);
    });

    it('« Suivant » on the last card goes nowhere', async () => {
        await load(dialog(['un', 'deux']));

        click('data-discovery-next');
        click('data-discovery-next');

        expect(visibleCardId()).toBe('deux');
        expect(position()).toBe('2 / 2');
    });

    it('sends nothing at all while the dialog is open', async () => {
        await load(dialog(['un', 'deux', 'trois']));

        click('data-discovery-next');
        click('data-discovery-next');

        expect(posted).toEqual([]);
    });

    it('sends exactly one call at the close, with the ids actually gone past', async () => {
        await load(dialog(['un', 'deux', 'trois']));

        click('data-discovery-next');
        document.getElementById('help-discovery-modal').dispatchEvent(new Event('hidden.bs.modal'));

        expect(posted).toHaveLength(1);
        expect(posted[0].url).toBe('/api/aide/decouverte');
        expect(posted[0].body).toEqual({ ids: ['un', 'deux'], action: 'close' });
    });

    it('records the first card even when the dialog is dismissed at once', async () => {
        await load(dialog(['un', 'deux', 'trois']));

        document.getElementById('help-discovery-modal').dispatchEvent(new Event('hidden.bs.modal'));

        expect(posted).toEqual([{ url: '/api/aide/decouverte', body: { ids: ['un'], action: 'close' } }]);
    });

    it('never counts a card twice', async () => {
        await load(dialog(['un', 'deux']));

        click('data-discovery-next');
        click('data-discovery-next');
        document.getElementById('help-discovery-modal').dispatchEvent(new Event('hidden.bs.modal'));

        expect(posted[0].body.ids).toEqual(['un', 'deux']);
    });

    it('« Pas avant une semaine » sends snooze, once, and the dismissal adds nothing', async () => {
        await load(dialog(['un', 'deux']));

        click('data-discovery-snooze');
        await Promise.resolve();
        await Promise.resolve();

        expect(posted).toHaveLength(1);
        expect(posted[0].body).toEqual({ ids: ['un'], action: 'snooze' });
        expect(hidden).toBe(1);
    });

    it('« Ne plus me proposer » sends never, and the close that follows does not overwrite it', async () => {
        await load(dialog(['un', 'deux']));

        click('data-discovery-never');
        await Promise.resolve();
        await Promise.resolve();
        document.getElementById('help-discovery-modal').dispatchEvent(new Event('hidden.bs.modal'));

        expect(posted).toHaveLength(1);
        expect(posted[0].body.action).toBe('never');
    });

    it('« Voir d\'autres astuces » sends more and then reloads', async () => {
        await load(dialog(['un', 'deux'], true));
        const reload = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { reload },
        });

        click('data-discovery-next');
        click('data-discovery-more');
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(posted).toHaveLength(1);
        expect(posted[0].body).toEqual({ ids: ['un', 'deux'], action: 'more' });
        expect(reload).toHaveBeenCalledOnce();
    });

    /**
     * Offline, the toolbox is there but the call fails — and with no
     * toolbox at all (a shell that never loaded api.js) the script must
     * still not throw, since it runs on every page.
     */
    it('survives a page with no fetch toolbox', async () => {
        document.body.innerHTML = dialog(['un']);
        posted = [];
        shown = 0;
        window.ScoutMagicApi = undefined;
        window.bootstrap = /** @type {any} */ ({
            Modal: {
                getOrCreateInstance: () => ({ show: () => {}, hide: () => {} }),
                getInstance: () => null,
            },
        });
        vi.resetModules();
        await import('../../public/assets/js/help-discovery.js');

        expect(() => {
            document.getElementById('help-discovery-modal').dispatchEvent(new Event('hidden.bs.modal'));
        }).not.toThrow();
        expect(posted).toEqual([]);
    });
});
