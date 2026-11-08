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
            <button type="button" data-bs-dismiss="modal" aria-label="Fermer">×</button>
            <div class="modal-body">
                <div class="alert alert-danger d-none" role="alert" data-discovery-error>
                    <p data-discovery-error-message></p>
                    <button type="button" data-discovery-retry>Réessayer</button>
                </div>
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
    window.ScoutMagicToast = { show: vi.fn() };

    window.ScoutMagicApi = /** @type {any} */ ({
        postJson: (url, body) => {
            posted.push({ url, body });
            return Promise.resolve({ ok: true, status: 200, data: { success: true } });
        },
    });

    const instance = {
        show: () => {
            shown += 1;
            document.getElementById('help-discovery-modal').classList.add('show');
        },
        hide: () => {
            hidden += 1;
            dismiss();
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

function dismiss() {
    const modal = document.getElementById('help-discovery-modal');
    modal.classList.remove('show');
    modal.dispatchEvent(new Event('hidden.bs.modal'));
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

/** Wait for the request and its UI callbacks to finish, rather than count microtasks. */
function afterRequest() {
    return new Promise((resolve) => setTimeout(resolve, 0));
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
        dismiss();
        await afterRequest();

        expect(posted).toHaveLength(1);
        expect(posted[0].url).toBe('/api/aide/decouverte');
        expect(posted[0].body).toEqual({ ids: ['un', 'deux'], action: 'close' });
    });

    it('records the first card even when the dialog is dismissed at once', async () => {
        await load(dialog(['un', 'deux', 'trois']));

        dismiss();
        await afterRequest();

        expect(posted).toEqual([{ url: '/api/aide/decouverte', body: { ids: ['un'], action: 'close' } }]);
    });

    it('never counts a card twice', async () => {
        await load(dialog(['un', 'deux']));

        click('data-discovery-next');
        click('data-discovery-next');
        dismiss();
        await afterRequest();

        expect(posted[0].body.ids).toEqual(['un', 'deux']);
    });

    it('« Pas avant une semaine » sends snooze, once, and the dismissal adds nothing', async () => {
        await load(dialog(['un', 'deux']));

        click('data-discovery-snooze');
        await afterRequest();

        expect(posted).toHaveLength(1);
        expect(posted[0].body).toEqual({ ids: ['un'], action: 'snooze' });
        expect(hidden).toBe(1);
    });

    it('« Ne plus me proposer » sends never, and the close that follows does not overwrite it', async () => {
        await load(dialog(['un', 'deux']));

        click('data-discovery-never');
        await afterRequest();
        dismiss();

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
        await afterRequest();

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
            dismiss();
        }).not.toThrow();
        expect(posted).toEqual([]);
    });
    it.each([
        ['HTTP failure', { ok: false, status: 500, data: { success: true } }],
        ['application failure', { ok: true, status: 200, data: { success: false } }],
    ])('keeps a snooze visible after %s and permits retry', async (_label, response) => {
        await load(dialog(['un', 'deux']));
        window.ScoutMagicApi.postJson = vi.fn().mockResolvedValue(response);
        click('data-discovery-snooze');
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(hidden).toBe(0);
        expect(document.querySelector('[data-discovery-error]').classList.contains('d-none')).toBe(false);
        expect(document.querySelector('[data-discovery-snooze]').disabled).toBe(false);
    });

    it('keeps the error and retry inside the open modal without interpreting message markup', async () => {
        await load(dialog(['un', 'deux']));
        const message = '<img src=x onerror=alert(1)> Réessayez.';
        const post = vi.fn()
            .mockResolvedValueOnce({ ok: false, status: 503, data: { success: false, error: message } })
            .mockResolvedValueOnce({ ok: true, status: 200, data: { success: true } });
        window.ScoutMagicApi.postJson = post;
        click('data-discovery-snooze');
        await afterRequest();

        const notice = document.querySelector('#help-discovery-modal [data-discovery-error]');
        expect(notice.classList.contains('d-none')).toBe(false);
        expect(notice.querySelector('[data-discovery-error-message]').textContent).toBe(message);
        expect(notice.querySelector('img')).toBeNull();
        expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
        expect(notice.querySelector('[data-discovery-retry]').disabled).toBe(false);
        click('data-discovery-retry');
        await afterRequest();
        expect(post.mock.calls.map(call => call[1].action)).toEqual(['snooze', 'snooze']);
        expect(hidden).toBe(1);
    });

    it('waits for both acknowledgements before closing and disables duplicate actions meanwhile', async () => {
        await load(dialog(['un', 'deux']));
        let resolve;
        const post = vi.fn(() => new Promise((done) => { resolve = done; }));
        window.ScoutMagicApi.postJson = post;
        click('data-discovery-snooze');
        await Promise.resolve();
        expect(hidden).toBe(0);
        expect(document.querySelector('[data-discovery-snooze]').disabled).toBe(true);
        expect(document.querySelector('[data-bs-dismiss]').disabled).toBe(false);
        click('data-discovery-never');
        click('data-discovery-next');
        dismiss();
        expect(post).toHaveBeenCalledOnce();
        expect(post.mock.calls[0][1]).toEqual({ ids: ['un'], action: 'snooze' });
        expect(visibleCardId()).toBe('un');
        resolve({ ok: true, status: 200, data: { success: true } });
        await afterRequest();
        expect(hidden).toBe(1);
        expect(post).toHaveBeenCalledOnce();
    });

    it('retries the same snooze after a network failure', async () => {
        await load(dialog(['un', 'deux']));
        const post = vi.fn()
            .mockResolvedValueOnce({ ok: false, status: 0, data: null })
            .mockResolvedValueOnce({ ok: true, status: 200, data: { success: true } });
        window.ScoutMagicApi.postJson = post;
        click('data-discovery-snooze');
        await afterRequest();
        expect(hidden).toBe(0);
        expect(document.querySelector('[data-discovery-snooze]').disabled).toBe(false);
        click('data-discovery-snooze');
        await afterRequest();
        expect(hidden).toBe(1);
        expect(post.mock.calls.map((call) => call[1])).toEqual([
            { ids: ['un'], action: 'snooze' }, { ids: ['un'], action: 'snooze' },
        ]);
    });

    it.each(['never', 'more'])('keeps %s retryable instead of hiding or reloading on a refused write', async (action) => {
        await load(dialog(['un', 'deux'], true));
        const reload = vi.fn();
        Object.defineProperty(window, 'location', { configurable: true, value: { reload } });
        const post = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, data: { success: false, error: 'Réessayez plus tard.' } })
            .mockResolvedValueOnce({ ok: true, status: 200, data: { success: true } });
        window.ScoutMagicApi.postJson = post;
        click('data-discovery-next');
        click('data-discovery-' + action);
        await afterRequest();
        expect(hidden).toBe(0);
        expect(reload).not.toHaveBeenCalled();
        expect(document.querySelector('[data-discovery-error-message]').textContent).toBe('Réessayez plus tard.');
        expect(window.ScoutMagicToast.show).not.toHaveBeenCalled();
        click('data-discovery-retry');
        await afterRequest();
        expect(post.mock.calls.map((call) => call[1].action)).toEqual([action, action]);
        expect(hidden).toBe(action === 'never' ? 1 : 0);
        expect(reload).toHaveBeenCalledTimes(action === 'more' ? 1 : 0);
    });

    it('offers a retry through the common toast when an ordinary dismissal cannot be recorded', async () => {
        await load(dialog(['un', 'deux']));
        const post = vi.fn()
            .mockResolvedValueOnce({ ok: false, status: 403, data: { success: false, error: 'Session expirée.' } })
            .mockResolvedValueOnce({ ok: true, status: 200, data: { success: true } });
        window.ScoutMagicApi.postJson = post;
        dismiss();
        await afterRequest();
        expect(hidden).toBe(0);
        expect(window.ScoutMagicToast.show).toHaveBeenCalledWith('Session expirée.', expect.objectContaining({ variant: 'error' }));
        window.ScoutMagicToast.show.mock.calls[0][1].action.onClick();
        await afterRequest();
        expect(post.mock.calls.map((call) => call[1])).toEqual([
            { ids: ['un'], action: 'close' }, { ids: ['un'], action: 'close' },
        ]);
        expect(hidden).toBe(0);
    });

    it('offers the pending choice through the toast if the reader dismissed before its failure', async () => {
        await load(dialog(['un']));
        let respond;
        const post = vi.fn()
            .mockImplementationOnce(() => new Promise(resolve => { respond = resolve; }))
            .mockResolvedValueOnce({ ok: true, data: { success: true } });
        window.ScoutMagicApi.postJson = post;
        click('data-discovery-snooze');
        await Promise.resolve();
        dismiss();
        respond({ ok: false, status: 0, data: null });
        await afterRequest();
        expect(post).toHaveBeenCalledOnce();
        expect(document.querySelector('[data-discovery-error]').classList.contains('d-none')).toBe(true);
        window.ScoutMagicToast.show.mock.calls[0][1].action.onClick();
        await afterRequest();
        expect(post.mock.calls.map(call => call[1].action)).toEqual(['snooze', 'snooze']);
    });

    it.each(['snooze', 'never'])('preserves failed %s when dismissed after its failure', async (action) => {
        await load(dialog(['un', 'deux']));
        const message = 'Le choix ne peut pas être enregistré.';
        const post = vi.fn()
            .mockResolvedValueOnce({ ok: false, status: 503, data: { success: false, error: message } })
            .mockResolvedValueOnce({ ok: true, status: 200, data: { success: true } });
        window.ScoutMagicApi.postJson = post;
        click('data-discovery-' + action);
        await afterRequest();
        dismiss();
        await afterRequest();

        const toast = window.ScoutMagicToast.show.mock.calls.at(-1);
        const beforeRetry = {
            requests: post.mock.calls.map(([, body]) => body),
            toast: toast ? [toast[0], toast[1].variant, toast[1].action.label, typeof toast[1].action.onClick] : null,
        };
        toast?.[1]?.action?.onClick?.();
        await afterRequest();
        dismiss();
        await afterRequest();

        expect([beforeRetry, post.mock.calls.map(([, body]) => body)]).toEqual([
            {
                requests: [{ ids: ['un'], action }],
                toast: [message, 'error', 'Réessayer', 'function'],
            },
            [{ ids: ['un'], action }, { ids: ['un'], action }],
        ]);
    });

    it.each(['snooze', 'never'])('keeps failed account-wide %s visible while browsing cards', async (action) => {
        await load(dialog(['un', 'deux', 'trois']));
        const message = 'Le choix ne peut pas être enregistré.';
        const post = vi.fn()
            .mockResolvedValueOnce({ ok: false, status: 503, data: { success: false, error: message } })
            .mockResolvedValueOnce({ ok: true, status: 200, data: { success: true } });
        window.ScoutMagicApi.postJson = post;
        click('data-discovery-' + action);
        await afterRequest();
        click('data-discovery-next');
        const beforeRetry = [
            visibleCardId(),
            !document.querySelector('[data-discovery-error]').classList.contains('d-none'),
            document.querySelector('[data-discovery-error-message]').textContent,
            post.mock.calls.map(([, body]) => body),
        ];
        click('data-discovery-retry');
        await afterRequest();

        expect([beforeRetry, document.getElementById('help-discovery-modal').classList.contains('show'),
            post.mock.calls.map(([, body]) => body)]).toEqual([
            ['deux', true, message, [{ ids: ['un'], action }]],
            false,
            [{ ids: ['un'], action }, { ids: ['un', 'deux'], action }],
        ]);
    });

    it.each([
        ['missing JSON', { ok: true, status: 200, data: null }],
        ['truthy but non-boolean success', { ok: true, status: 200, data: { success: 'true' } }],
        ['missing response', null],
    ])('does not settle on %s', async (_label, response) => {
        await load(dialog(['un']));
        window.ScoutMagicApi.postJson = vi.fn().mockResolvedValue(response);
        click('data-discovery-snooze');
        await afterRequest();
        expect(hidden).toBe(0);
        expect(document.querySelector('[data-discovery-error]').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('help-discovery-modal').getAttribute('aria-busy')).toBe('false');
    });

    it('handles an unexpected rejection and restores the controls for a retry', async () => {
        await load(dialog(['un']));
        window.ScoutMagicApi.postJson = vi.fn().mockRejectedValue(new Error('Network failed'));
        click('data-discovery-snooze');
        await afterRequest();
        expect(hidden).toBe(0);
        expect(document.querySelector('[data-discovery-error]').classList.contains('d-none')).toBe(false);
        expect(document.querySelector('[data-discovery-snooze]').disabled).toBe(false);
    });

    it('leaves a missing API retryable and preserves controls that were already disabled', async () => {
        await load(dialog(['un']));
        const api = window.ScoutMagicApi;
        window.ScoutMagicApi = undefined;
        document.querySelector('[data-discovery-never]').disabled = true;
        click('data-discovery-snooze');
        await afterRequest();
        expect(hidden).toBe(0);
        expect(document.querySelector('[data-discovery-never]').disabled).toBe(true);
        expect(document.querySelector('[data-discovery-snooze]').disabled).toBe(false);
        window.ScoutMagicApi = api;
        click('data-discovery-snooze');
        await afterRequest();
        expect(hidden).toBe(1);
    });

});
